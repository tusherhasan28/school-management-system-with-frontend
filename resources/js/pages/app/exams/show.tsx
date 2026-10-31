import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Calculator, Check, CheckCheck, FileText, Lock, Pencil, Plus, Printer, RotateCcw, Send, Trash2, Unlock } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import ExamController from '@/actions/App/Modules/Exams/Http/Controllers/ExamController';
import MarkSheetController from '@/actions/App/Modules/Exams/Http/Controllers/MarkSheetController';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, SelectField, selectClass, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, reportCards, results } from '@/routes/exams';
import { show as sheetRoute } from '@/routes/marks';
import { examTone } from './index';

type Component = { id?: number; name: string; name_bn: string | null; full_marks: string; pass_marks: string | null };
type Sheet = { id: number; section: string; status: string; done: number; total: number };
type ExamSubject = {
    id: number; subject: string; subject_bn: string | null; subject_type: string; full_marks: string; pass_marks: string;
    exam_date: string | null; starts_at: string | null; duration_minutes: number | null; components: Component[]; sheets: Sheet[];
};

type Props = {
    exam: {
        id: number; name: string; name_bn: string | null; status: string; exam_type_id: number | null; type: string | null; grading_scale_id: number;
        scale: string | null; session: string | null; starts_on: string | null; ends_on: string | null; approved_at: string | null; published_at: string | null;
        calculation: { version: number; at: string; by: string | null; students: number } | null; stale: boolean; versions: number;
    };
    classes: { id: number; name: string; subjects: ExamSubject[] }[];
    options: { classes: { id: number; name: string }[]; templates: string[]; types: { id: number; name: string }[]; scales: { id: number; name: string }[] };
    can: { manage: boolean; approve: boolean; publish: boolean; verify: boolean; results: boolean };
};

const steps = ['draft', 'marks_entry', 'calculated', 'approved', 'published'];
export const sheetTone: Record<string, string> = { draft: 'archived', submitted: 'pending', verified: 'active' };

export default function ExamShow({ exam, classes, options, can }: Props) {
    const t = useT();
    const { locale, errors } = usePage().props as { locale: string; errors: Record<string, string> };
    const sheets = classes.flatMap((c) => c.subjects.flatMap((s) => s.sheets));
    const verified = sheets.filter((s) => s.status === 'verified').length;
    const submitted = sheets.filter((s) => s.status === 'submitted').length;
    const allVerified = sheets.length > 0 && verified === sheets.length;
    const draft = exam.status === 'draft';
    const stepIndex = steps.indexOf(exam.status);
    const post = (url: string, data = {}) => router.post(url, data, { preserveScroll: true });

    return (
        <>
            <Head title={exam.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={locale === 'bn' && exam.name_bn ? exam.name_bn : exam.name}
                    description={[exam.session, exam.type, exam.scale, exam.starts_on && `${formatDate(exam.starts_on)}${exam.ends_on && exam.ends_on !== exam.starts_on ? ` → ${formatDate(exam.ends_on)}` : ''}`].filter(Boolean).join(' · ')}
                    actions={
                        <>
                            {exam.calculation && (can.results || can.manage) && (
                                <>
                                    <Button variant="outline" asChild><Link href={results(exam.id)}><FileText />{t('Results')}</Link></Button>
                                    <Button variant="outline" asChild><a href={reportCards.url(exam.id)} target="_blank" rel="noreferrer"><Printer />{t('Report cards')}</a></Button>
                                </>
                            )}
                            {can.manage && <EditExam exam={exam} options={options} />}
                        </>
                    }
                />

                <ol className="grid grid-cols-5 gap-1 text-xs sm:text-sm" aria-label={t('Progress')}>
                    {steps.map((s, i) => (
                        <li key={s} className={cn('flex flex-col gap-1.5', i <= stepIndex ? 'text-foreground' : 'text-muted-foreground')}>
                            <span className={cn('h-1.5 rounded-full', i < stepIndex ? 'bg-primary' : i === stepIndex ? 'bg-primary/70' : 'bg-muted')} />
                            <span className={cn(i === stepIndex && 'font-medium')}>{t('exam_' + s)}</span>
                        </li>
                    ))}
                </ol>

                <section className="flex flex-col gap-3 rounded-xl border border-border/70 bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1 text-sm">
                        {draft && <p>{t('Add each class\'s subjects and set the marks distribution, then open marks entry. Setup is locked after that.')}</p>}
                        {exam.status === 'marks_entry' && (
                            <p>{t(':verified of :total mark sheets verified', { verified, total: sheets.length })}{submitted > 0 && ` · ${t(':count waiting for verification', { count: submitted })}`}</p>
                        )}
                        {exam.calculation && (
                            <p className="text-muted-foreground">
                                {t('Version :version calculated :at by :by for :count students', { version: exam.calculation.version, at: formatDateTime(exam.calculation.at), by: exam.calculation.by ?? '—', count: exam.calculation.students })}
                            </p>
                        )}
                        {exam.published_at && <p className="font-medium text-emerald-700 dark:text-emerald-400">{t('Published :at', { at: formatDateTime(exam.published_at) })}</p>}
                        {exam.stale && exam.status !== 'published' && (
                            <p className="flex items-center gap-1.5 text-amber-700 dark:text-amber-400"><AlertTriangle className="size-4" />{t('The grading scale changed after the last calculation. Calculate again before approving or publishing.')}</p>
                        )}
                        <InputError message={errors.exam} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {draft && can.manage && (
                            <ConfirmDialog
                                trigger={<Button disabled={sheets.length === 0 && classes.length === 0}><Unlock />{t('Open marks entry')}</Button>}
                                title={t('Open marks entry?')}
                                description={t('Teachers can then enter marks. Subjects and marks distribution can no longer change.')}
                                onConfirm={() => post(ExamController.open.url(exam.id))}
                            />
                        )}
                        {exam.status === 'marks_entry' && can.verify && submitted > 0 && (
                            <Button variant="outline" onClick={() => post(MarkSheetController.verifyAll.url(exam.id))}><CheckCheck />{t('Verify submitted sheets')}</Button>
                        )}
                        {['marks_entry', 'calculated'].includes(exam.status) && can.manage && (
                            <Button variant={exam.status === 'calculated' ? 'outline' : 'default'} disabled={!allVerified} onClick={() => post(ExamController.calculate.url(exam.id))}>
                                <Calculator />{exam.status === 'calculated' ? t('Calculate again') : t('Calculate results')}
                            </Button>
                        )}
                        {exam.status === 'calculated' && can.approve && (
                            <ConfirmDialog
                                trigger={<Button disabled={exam.stale}><Check />{t('Approve results')}</Button>}
                                title={t('Approve these results?')}
                                description={t('Check the results and a few report cards first. After approval the results can be published.')}
                                onConfirm={() => post(ExamController.approve.url(exam.id))}
                            />
                        )}
                        {exam.status === 'approved' && can.publish && <PublishDialog examId={exam.id} disabled={exam.stale} />}
                        {['calculated', 'approved'].includes(exam.status) && can.manage && <ReasonDialog examId={exam.id} label={t('Reopen marks entry')} />}
                        {exam.status === 'published' && can.publish && <ReasonDialog examId={exam.id} label={t('Withdraw and reopen')} destructive />}
                    </div>
                </section>

                {classes.length === 0 ? (
                    <EmptyState icon={FileText} title={t('No subjects yet')} description={draft ? t('Add a class to bring in its subjects.') : undefined} />
                ) : (
                    classes.map((c) => (
                        <section key={c.id} className="overflow-hidden rounded-xl border border-border/70 bg-card">
                            <header className="border-b border-border/70 px-4 py-3"><h2 className="font-medium">{c.name}</h2></header>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-4 py-2">{t('Subject')}</th><th className="px-4 py-2">{t('Marks distribution')}</th>
                                            <th className="px-4 py-2">{t('Full / pass')}</th><th className="px-4 py-2">{t('Exam date')}</th>
                                            <th className="px-4 py-2">{draft ? '' : t('Mark sheets')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border/70">
                                        {c.subjects.map((s) => (
                                            <tr key={s.id} className="align-top">
                                                <td className="px-4 py-2.5">
                                                    <p className="font-medium">{locale === 'bn' && s.subject_bn ? s.subject_bn : s.subject}</p>
                                                    {s.subject_type !== 'compulsory' && <Badge variant="secondary" className="mt-1">{t('subject_type_' + s.subject_type)}</Badge>}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {s.components.map((cp) => `${locale === 'bn' && cp.name_bn ? cp.name_bn : cp.name} ${Number(cp.full_marks)}${cp.pass_marks ? ` (${t('pass')} ${Number(cp.pass_marks)})` : ''}`).join(' · ')}
                                                </td>
                                                <td className="px-4 py-2.5 tabular-nums">{Number(s.full_marks)} / {Number(s.pass_marks)}</td>
                                                <td className="px-4 py-2.5 whitespace-nowrap">{s.exam_date ? `${formatDate(s.exam_date)}${s.starts_at ? ` · ${s.starts_at}` : ''}` : '—'}</td>
                                                <td className="px-4 py-2.5">
                                                    {draft ? (
                                                        can.manage && (
                                                            <div className="flex justify-end gap-1">
                                                                <SubjectEditor subject={s} />
                                                                <Button size="icon" variant="ghost" aria-label={t('Remove :name', { name: s.subject })} onClick={() => router.delete(ExamController.destroySubject.url(s.id), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>
                                                            </div>
                                                        )
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {s.sheets.map((sh) => (
                                                                <Link key={sh.id} href={sheetRoute(sh.id)} className="inline-flex items-center gap-1.5 rounded-md border border-border/70 px-2 py-1 text-xs hover:bg-muted">
                                                                    <span className="font-medium">{sh.section}</span>
                                                                    <span className="tabular-nums text-muted-foreground">{sh.done}/{sh.total}</span>
                                                                    <StatusBadge status={sheetTone[sh.status]} label={t('sheet_' + sh.status)} />
                                                                </Link>
                                                            ))}
                                                            {can.manage && <SubjectEditor subject={s} scheduleOnly />}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))
                )}

                {draft && can.manage && (
                    <Form {...ExamController.addSubjects.form(exam.id)} options={{ preserveScroll: true }} className="flex flex-wrap items-end gap-3 rounded-xl border border-dashed border-border p-4">
                        {({ processing, errors: e }) => (
                            <>
                                <SelectField fieldClassName="min-w-48 flex-1" label={t('Class')} name="class_id" required placeholder={t('Choose a class…')} options={options.classes.map((c) => ({ value: c.id, label: c.name }))} error={e.class_id} />
                                <SelectField fieldClassName="min-w-48 flex-1" label={t('Marks distribution')} name="template" options={options.templates.map((tp) => ({ value: tp, label: t('template_' + tp) }))} error={e.template} />
                                <Button type="submit" variant="outline" disabled={processing}>{processing && <Spinner />}<Plus />{t('Add class subjects')}</Button>
                                <p className="basis-full text-xs text-muted-foreground">{t('Every subject mapped to the class in Academics is added with its full and pass marks. Adjust each subject afterwards if needed.')}</p>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

function EditExam({ exam, options }: Pick<Props, 'exam' | 'options'>) {
    const t = useT();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button variant="outline"><Pencil />{t('Edit')}</Button></DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{t('Edit exam')}</DialogTitle>
                <DialogDescription>{exam.status === 'draft' ? t('Details can change at any time; the grading scale only while the exam is a draft.') : t('The grading scale is fixed once marks entry has started.')}</DialogDescription>
                <Form {...ExamController.update.form(exam.id)} options={{ preserveScroll: true }} onSuccess={() => setOpen(false)} className="grid gap-3">
                    {({ processing, errors }) => (
                        <>
                            <TextField label={t('Name')} name="name" required defaultValue={exam.name} error={errors.name} />
                            <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" defaultValue={exam.name_bn ?? ''} error={errors.name_bn} />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <SelectField label={t('Exam type')} name="exam_type_id" placeholder="—" defaultValue={exam.exam_type_id ?? ''} options={options.types.map((ty) => ({ value: ty.id, label: ty.name }))} error={errors.exam_type_id} />
                                {exam.status === 'draft' && <SelectField label={t('Grading scale')} name="grading_scale_id" defaultValue={exam.grading_scale_id} options={options.scales.map((s) => ({ value: s.id, label: s.name }))} error={errors.grading_scale_id} />}
                                <TextField label={t('Starts')} name="starts_on" type="date" defaultValue={exam.starts_on ?? ''} error={errors.starts_on} />
                                <TextField label={t('Ends')} name="ends_on" type="date" defaultValue={exam.ends_on ?? ''} error={errors.ends_on} />
                            </div>
                            <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function SubjectEditor({ subject, scheduleOnly = false }: { subject: ExamSubject; scheduleOnly?: boolean }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({
        subject_type: subject.subject_type,
        pass_marks: subject.pass_marks,
        exam_date: subject.exam_date ?? '',
        starts_at: subject.starts_at ?? '',
        duration_minutes: subject.duration_minutes ?? '',
        components: subject.components.map((c) => ({ ...c, name_bn: c.name_bn ?? '', pass_marks: c.pass_marks ?? '' })),
    });
    const errors = form.errors as Record<string, string>;
    const full = form.data.components.reduce((sum, c) => sum + (Number(c.full_marks) || 0), 0);
    const setComponent = (i: number, patch: Partial<{ name: string; name_bn: string; full_marks: string; pass_marks: string }>) => form.setData('components', form.data.components.map((c, n) => (n === i ? { ...c, ...patch } : c)));

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size={scheduleOnly ? 'sm' : 'icon'} variant="ghost" aria-label={t('Edit :name', { name: subject.subject })}>{scheduleOnly ? t('Schedule') : <Pencil />}</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogTitle>{subject.subject}</DialogTitle>
                <DialogDescription>{scheduleOnly ? t('Marks distribution is locked; only the schedule can change.') : t('Full marks are the sum of the parts. A part may have its own pass mark.')}</DialogDescription>
                <div className="grid gap-4">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label={t('Exam date')} name="exam_date" error={errors.exam_date}><Input id="exam_date" type="date" value={form.data.exam_date} onChange={(e) => form.setData('exam_date', e.target.value)} /></Field>
                        <Field label={t('Start time')} name="starts_at" error={errors.starts_at}><Input id="starts_at" type="time" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></Field>
                        <Field label={t('Duration (minutes)')} name="duration_minutes" error={errors.duration_minutes}><Input id="duration_minutes" type="number" min={5} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', e.target.value)} /></Field>
                    </div>
                    {!scheduleOnly && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Field label={t('Counts as')} name="subject_type" error={errors.subject_type}>
                                    <select id="subject_type" className={selectClass} value={form.data.subject_type} onChange={(e) => form.setData('subject_type', e.target.value)}>
                                        {['compulsory', 'optional', 'non_gpa'].map((ty) => <option key={ty} value={ty}>{t('subject_type_' + ty)}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('Pass marks')} name="pass_marks" error={errors.pass_marks}><Input id="pass_marks" inputMode="decimal" value={form.data.pass_marks} onChange={(e) => form.setData('pass_marks', e.target.value)} /></Field>
                                <div className="grid gap-1.5 text-sm"><span className="font-medium">{t('Full marks')}</span><span className="flex h-9 items-center text-lg font-semibold tabular-nums">{full}</span></div>
                            </div>
                            <div className="grid gap-2">
                                {form.data.components.map((c, i) => (
                                    <div key={i} className="grid grid-cols-[1fr_1fr_5rem_5rem_auto] items-end gap-2">
                                        <Field label={i === 0 ? t('Part') : ''} name={`c${i}n`}><Input id={`c${i}n`} value={c.name} onChange={(e) => setComponent(i, { name: e.target.value })} aria-label={t('Part')} /></Field>
                                        <Field label={i === 0 ? t('Part (Bangla)') : ''} name={`c${i}b`}><Input id={`c${i}b`} lang="bn" value={c.name_bn ?? ''} onChange={(e) => setComponent(i, { name_bn: e.target.value })} aria-label={t('Part (Bangla)')} /></Field>
                                        <Field label={i === 0 ? t('Full') : ''} name={`c${i}f`}><Input id={`c${i}f`} inputMode="decimal" value={c.full_marks} onChange={(e) => setComponent(i, { full_marks: e.target.value })} aria-label={t('Full marks')} /></Field>
                                        <Field label={i === 0 ? t('Pass') : ''} name={`c${i}p`}><Input id={`c${i}p`} inputMode="decimal" value={c.pass_marks ?? ''} onChange={(e) => setComponent(i, { pass_marks: e.target.value })} aria-label={t('Pass marks')} /></Field>
                                        <Button size="icon" variant="ghost" disabled={form.data.components.length === 1} aria-label={t('Remove')} onClick={() => form.setData('components', form.data.components.filter((_, n) => n !== i))}><Trash2 /></Button>
                                    </div>
                                ))}
                                <Button size="sm" variant="outline" className="justify-self-start" onClick={() => form.setData('components', [...form.data.components, { name: '', name_bn: '', full_marks: '', pass_marks: '' }])}><Plus />{t('Add part')}</Button>
                                <InputError message={errors.components ?? Object.entries(errors).find(([k]) => k.startsWith('components.'))?.[1]} />
                            </div>
                        </>
                    )}
                    <Button onClick={() => form.put(ExamController.updateSubject.url(subject.id), { preserveScroll: true, onSuccess: () => setOpen(false) })} disabled={form.processing}>{form.processing && <Spinner />}{t('Save')}</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function PublishDialog({ examId, disabled }: { examId: number; disabled: boolean }) {
    const t = useT();
    const [notify, setNotify] = useState(true);
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button disabled={disabled}><Send />{t('Publish results')}</Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{t('Publish results?')}</DialogTitle>
                <DialogDescription>{t('Published results are final. The grading scale used is locked, and a correction later needs a reason and a new calculation.')}</DialogDescription>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={notify} onChange={(e) => setNotify(e.target.checked)} />{t('Email a summary to each primary guardian')}</label>
                <Button onClick={() => router.post(ExamController.publish.url(examId), { notify }, { preserveScroll: true, onSuccess: () => setOpen(false) })}><Lock />{t('Publish')}</Button>
            </DialogContent>
        </Dialog>
    );
}

function ReasonDialog({ examId, label, destructive = false }: { examId: number; label: string; destructive?: boolean }): ReactNode {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button variant={destructive ? 'outline' : 'ghost'} className={destructive ? 'text-destructive' : undefined}><RotateCcw />{label}</Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{label}</DialogTitle>
                <DialogDescription>{t('Marks entry opens again. Results calculated so far stay on record; the next calculation becomes a new version.')}</DialogDescription>
                <Field label={t('Reason')} name="reason" required error={form.errors.reason}><Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                <Button variant={destructive ? 'destructive' : 'default'} disabled={form.processing} onClick={() => form.post(ExamController.reopen.url(examId), { preserveScroll: true, onSuccess: () => setOpen(false) })}>{form.processing && <Spinner />}{label}</Button>
            </DialogContent>
        </Dialog>
    );
}

ExamShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Exams & results', href: index() }] };
