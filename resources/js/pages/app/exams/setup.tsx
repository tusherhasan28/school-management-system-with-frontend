import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Lock, Scale, Star, Trash2 } from 'lucide-react';
import ExamSetupController from '@/actions/App/Modules/Exams/Http/Controllers/ExamSetupController';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/shared/empty-state';
import { FormSection, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { setup } from '@/routes/exams';
import { edit } from '@/routes/grading-scales';

type Props = {
    types: { id: number; name: string; name_bn: string | null; in_use: boolean }[];
    scales: { id: number; name: string; description: string | null; is_default: boolean; locked: boolean; items_count: number; gpa_method: string }[];
    presets: { key: string; name: string; description: string }[];
};

export default function ExamSetup({ types, scales, presets }: Props) {
    const t = useT();
    const { locale, errors } = usePage().props as { locale: string; errors: Record<string, string> };

    return (
        <>
            <Head title={t('Exam setup')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Exam setup')} description={t('Grading scales and exam types used by every exam in your school.')} />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="grid content-start gap-4 lg:col-span-2">
                        <h2 className="font-medium">{t('Grading scales')}</h2>
                        {scales.length === 0 ? (
                            <EmptyState icon={Scale} title={t('No grading scale yet')} description={t('Start from a preset, then adjust the bands and rules to match your board or school.')} />
                        ) : (
                            <ul className="grid gap-3 sm:grid-cols-2">
                                {scales.map((s) => (
                                    <li key={s.id}>
                                        <Link href={edit(s.id)} className="flex h-full flex-col gap-2 rounded-xl border border-border/70 bg-card p-4 transition hover:border-primary/40">
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="font-medium">{s.name}</p>
                                                <div className="flex gap-1">
                                                    {s.is_default && <Badge variant="secondary"><Star className="size-3" />{t('Default')}</Badge>}
                                                    {s.locked && <Badge variant="outline"><Lock className="size-3" />{t('Locked')}</Badge>}
                                                </div>
                                            </div>
                                            {s.description && <p className="line-clamp-2 text-sm text-muted-foreground">{s.description}</p>}
                                            <p className="mt-auto text-xs text-muted-foreground">{t(':count grades', { count: s.items_count })} · {t('gpa_method_' + s.gpa_method)}</p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Form {...ExamSetupController.storeScale.form()} className="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-border p-4">
                            {({ processing }) => (
                                <>
                                    <label className="grid flex-1 gap-1.5 text-sm">
                                        <span className="font-medium">{t('New scale from preset')}</span>
                                        <select name="preset" className="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
                                            {presets.map((p) => <option key={p.key} value={p.key}>{t(p.name)}</option>)}
                                        </select>
                                    </label>
                                    <Button type="submit" variant="outline" disabled={processing}>{processing && <Spinner />}{t('Create')}</Button>
                                    <p className="basis-full text-xs text-muted-foreground">{t('Presets are starting points. Check every band and rule against your board or school instructions before publishing results.')}</p>
                                </>
                            )}
                        </Form>
                    </section>

                    <section className="grid content-start gap-4">
                        <FormSection title={t('Exam types')} description={t('For example class test, half-yearly, annual.')}>
                            <ul className="mb-4 divide-y divide-border/70 text-sm">
                                {types.map((ty) => (
                                    <li key={ty.id} className="flex items-center justify-between gap-2 py-2">
                                        <span>{locale === 'bn' && ty.name_bn ? ty.name_bn : ty.name}</span>
                                        {!ty.in_use && (
                                            <Button size="icon" variant="ghost" aria-label={t('Delete :name', { name: ty.name })} onClick={() => router.delete(ExamSetupController.destroyType.url(ty.id), { preserveScroll: true })}>
                                                <Trash2 className="text-destructive" />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                                {types.length === 0 && <li className="py-2 text-muted-foreground">{t('No exam types yet')}</li>}
                            </ul>
                            <InputError message={errors.type} />
                            <Form {...ExamSetupController.storeType.form()} options={{ preserveScroll: true }} resetOnSuccess className="grid gap-3">
                                {({ processing, errors: e }) => (
                                    <>
                                        <TextField label={t('Name')} name="name" required error={e.name} />
                                        <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" error={e.name_bn} />
                                        <Button type="submit" variant="outline" disabled={processing}>{processing && <Spinner />}{t('Add')}</Button>
                                    </>
                                )}
                            </Form>
                        </FormSection>
                    </section>
                </div>
            </div>
        </>
    );
}

ExamSetup.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Exam setup', href: setup() }] };
