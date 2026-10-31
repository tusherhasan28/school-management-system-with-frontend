import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Award, CalendarRange, Plus } from 'lucide-react';
import ExamController from '@/actions/App/Modules/Exams/Http/Controllers/ExamController';
import { EmptyState } from '@/components/shared/empty-state';
import { SelectField, selectClass, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index, setup, show } from '@/routes/exams';
import { cn } from '@/lib/utils';

type Exam = {
    id: number; name: string; name_bn: string | null; status: string; type: string | null; scale: string | null;
    starts_on: string | null; ends_on: string | null; subjects: number; published_at: string | null;
};

type Props = {
    exams: Exam[];
    sessionId: number;
    sessions: { id: number; name: string; is_current: boolean }[];
    types: { id: number; name: string; name_bn: string | null }[];
    scales: { id: number; name: string; is_default: boolean }[];
    can: { manage: boolean };
};

export const examTone: Record<string, string> = { draft: 'archived', marks_entry: 'pending', calculated: 'trial', approved: 'trial', published: 'active' };

export default function ExamsIndex({ exams, sessionId, sessions, types, scales, can }: Props) {
    const t = useT();
    const { locale } = usePage().props;
    const defaultScale = scales.find((s) => s.is_default)?.id ?? scales[0]?.id;

    const create = can.manage && (
        <Dialog>
            <DialogTrigger asChild>
                <Button disabled={scales.length === 0}><Plus />{t('New exam')}</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{t('New exam')}</DialogTitle>
                <DialogDescription>{t('Add subjects and marks distribution after creating the exam.')}</DialogDescription>
                <Form {...ExamController.store.form()} className="grid gap-3">
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="academic_session_id" value={sessionId} />
                            <TextField label={t('Name')} name="name" required placeholder={t('e.g. Half-Yearly Examination')} error={errors.name} />
                            <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" error={errors.name_bn} />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <SelectField label={t('Exam type')} name="exam_type_id" placeholder="—" options={types.map((ty) => ({ value: ty.id, label: locale === 'bn' && ty.name_bn ? ty.name_bn : ty.name }))} error={errors.exam_type_id} />
                                <SelectField label={t('Grading scale')} name="grading_scale_id" required defaultValue={defaultScale} options={scales.map((s) => ({ value: s.id, label: s.name }))} error={errors.grading_scale_id} />
                                <TextField label={t('Starts')} name="starts_on" type="date" error={errors.starts_on} />
                                <TextField label={t('Ends')} name="ends_on" type="date" error={errors.ends_on} />
                            </div>
                            <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Create exam')}</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );

    return (
        <>
            <Head title={t('Exams & results')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Exams & results')}
                    description={t('Set up each exam, collect marks, then calculate, approve and publish results.')}
                    actions={
                        <>
                            <select className={cn(selectClass, 'w-36')} value={sessionId} onChange={(e) => router.get(index.url(), { session: e.target.value }, { preserveState: true })} aria-label={t('Session')}>
                                {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                            {create}
                        </>
                    }
                />

                {can.manage && scales.length === 0 && (
                    <div className="rounded-xl border border-amber-300/60 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        {t('Create a grading scale first.')} <Link href={setup()} className="font-medium underline">{t('Exam setup')}</Link>
                    </div>
                )}

                {exams.length === 0 ? (
                    <EmptyState icon={Award} title={t('No exams in this session yet')} />
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {exams.map((e) => (
                            <li key={e.id}>
                                <Link href={show(e.id)} className="flex h-full flex-col gap-3 rounded-xl border border-border/70 bg-card p-4 transition hover:border-primary/40 hover:shadow-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">{locale === 'bn' && e.name_bn ? e.name_bn : e.name}</p>
                                            <p className="text-sm text-muted-foreground">{[e.type, e.scale].filter(Boolean).join(' · ')}</p>
                                        </div>
                                        <StatusBadge status={examTone[e.status]} label={t('exam_' + e.status)} />
                                    </div>
                                    <p className="mt-auto flex items-center gap-1.5 text-sm text-muted-foreground">
                                        <CalendarRange className="size-4" />
                                        {e.starts_on ? `${formatDate(e.starts_on)}${e.ends_on && e.ends_on !== e.starts_on ? ` → ${formatDate(e.ends_on)}` : ''}` : t('Dates not set')}
                                        <span aria-hidden>·</span>
                                        {t(':count subjects', { count: e.subjects })}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

ExamsIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Exams & results', href: index() }] };
