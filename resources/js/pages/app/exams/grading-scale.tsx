import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy, Lock, Plus, Star, Trash2 } from 'lucide-react';
import ExamSetupController from '@/actions/App/Modules/Exams/Http/Controllers/ExamSetupController';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Field, FormSection, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { setup, show } from '@/routes/exams';
import { examTone } from './index';

type Item = { grade: string; min_percentage: string; max_percentage: string; grade_point: string; is_fail: boolean; remarks: string | null; remarks_bn: string | null };

type Rules = {
    gpa_method: string; optional_bonus_above: string; gpa_cap: string | null; gpa_decimals: number; gpa_rounding: string;
    subject_mark_rounding: string; fail_if_any_subject_fails: boolean; optional_fail_counts: boolean; gpa_zero_on_fail: boolean;
    absent_is_fail: boolean; merit_order: string; rank_failed: boolean;
};

type Props = {
    scale: { id: number; name: string; description: string | null; is_default: boolean; locked: boolean; rules: Rules; items: Item[]; exams: { id: number; name: string; status: string }[] };
    options: Record<string, string[]>;
};

export default function GradingScaleEdit({ scale, options }: Props) {
    const t = useT();
    const form = useForm({
        name: scale.name,
        description: scale.description ?? '',
        items: scale.items.map((i) => ({ ...i, remarks: i.remarks ?? '', remarks_bn: i.remarks_bn ?? '' })),
        rules: { ...scale.rules, gpa_cap: scale.rules.gpa_cap ?? '' },
    });
    const errors = form.errors as Record<string, string>;
    const locked = scale.locked;
    const setItem = (i: number, patch: Partial<{ [K in keyof Item]: Item[K] & {} }>) => form.setData('items', form.data.items.map((it, n) => (n === i ? { ...it, ...patch } : it)));
    const setRule = <K extends keyof Rules>(key: K, value: Rules[K] | string) => form.setData('rules', { ...form.data.rules, [key]: value });
    const usesGpa = form.data.rules.gpa_method !== 'none';

    const select = (key: keyof Rules, label: string, hint?: string) => (
        <Field label={label} name={`rules.${key}`} hint={hint} error={errors[`rules.${key}`]}>
            <select id={`rules.${key}`} className={selectClass} disabled={locked} value={String(form.data.rules[key])} onChange={(e) => setRule(key, key === 'gpa_decimals' ? Number(e.target.value) : e.target.value)}>
                {(key === 'gpa_decimals' ? ['0', '1', '2'] : options[key]).map((o) => <option key={o} value={o}>{key === 'gpa_decimals' ? o : t('rule_' + key + '_' + o)}</option>)}
            </select>
        </Field>
    );

    const toggle = (key: keyof Rules, label: string) => (
        <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 accent-primary" disabled={locked} checked={Boolean(form.data.rules[key])} onChange={(e) => setRule(key, e.target.checked)} />
            <span>{label}</span>
        </label>
    );

    return (
        <>
            <Head title={scale.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={scale.name}
                    description={t('Grade bands and rules for calculating results. Published results keep the exact version they were made with.')}
                    actions={
                        <>
                            {!scale.is_default && <Button variant="outline" onClick={() => router.post(ExamSetupController.defaultScale.url(scale.id), {}, { preserveScroll: true })}><Star />{t('Make default')}</Button>}
                            <Button variant="outline" onClick={() => router.post(ExamSetupController.duplicateScale.url(scale.id))}><Copy />{t('Duplicate')}</Button>
                            {!locked && scale.exams.length === 0 && (
                                <ConfirmDialog
                                    trigger={<Button variant="ghost" className="text-destructive"><Trash2 />{t('Delete')}</Button>}
                                    title={t('Delete this grading scale?')}
                                    description={t('This cannot be undone.')}
                                    destructive
                                    onConfirm={() => router.delete(ExamSetupController.destroyScale.url(scale.id))}
                                />
                            )}
                        </>
                    }
                />

                {locked && (
                    <div className="flex items-center gap-2 rounded-xl border border-border/70 bg-muted/50 p-4 text-sm">
                        <Lock className="size-4 shrink-0" />
                        {t('Published results used this scale, so it is read-only. Duplicate it to make changes for future exams.')}
                    </div>
                )}
                <InputError message={errors.scale} />

                <FormSection title={t('Details')}>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label={t('Name')} name="name" required error={errors.name}><Input id="name" disabled={locked} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label={t('Description')} name="description" error={errors.description}><Input id="description" disabled={locked} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
                    </div>
                </FormSection>

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                        <div>
                            <h2 className="font-medium">{t('Grade bands')}</h2>
                            <p className="text-sm text-muted-foreground">{t('Bands must cover 0 to 100 with no gaps. Percentages are rounded down to two decimals before banding.')}</p>
                        </div>
                        {!locked && <Button size="sm" variant="outline" onClick={() => form.setData('items', [...form.data.items, { grade: '', min_percentage: '', max_percentage: '', grade_point: '0.00', is_fail: false, remarks: '', remarks_bn: '' }])}><Plus />{t('Add band')}</Button>}
                    </header>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-3 py-2">{t('Grade')}</th><th className="px-3 py-2">{t('From %')}</th><th className="px-3 py-2">{t('To %')}</th>
                                    <th className="px-3 py-2">{t('Grade point')}</th><th className="px-3 py-2">{t('Fail')}</th><th className="px-3 py-2">{t('Remarks')}</th><th className="px-3 py-2">{t('Remarks (Bangla)')}</th><th />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {form.data.items.map((it, i) => (
                                    <tr key={i} className={it.is_fail ? 'bg-rose-50/50 dark:bg-rose-500/5' : undefined}>
                                        <td className="px-3 py-1.5"><Input className="w-20" disabled={locked} value={it.grade} onChange={(e) => setItem(i, { grade: e.target.value })} aria-label={t('Grade')} /></td>
                                        <td className="px-3 py-1.5"><Input className="w-24 tabular-nums" inputMode="decimal" disabled={locked} value={it.min_percentage} onChange={(e) => setItem(i, { min_percentage: e.target.value })} aria-label={t('From %')} /></td>
                                        <td className="px-3 py-1.5"><Input className="w-24 tabular-nums" inputMode="decimal" disabled={locked} value={it.max_percentage} onChange={(e) => setItem(i, { max_percentage: e.target.value })} aria-label={t('To %')} /></td>
                                        <td className="px-3 py-1.5"><Input className="w-20 tabular-nums" inputMode="decimal" disabled={locked} value={it.grade_point} onChange={(e) => setItem(i, { grade_point: e.target.value })} aria-label={t('Grade point')} /></td>
                                        <td className="px-3 py-1.5"><input type="checkbox" className="size-4 accent-primary" disabled={locked} checked={it.is_fail} onChange={(e) => setItem(i, { is_fail: e.target.checked })} aria-label={t('Fail')} /></td>
                                        <td className="px-3 py-1.5"><Input className="min-w-28" disabled={locked} value={it.remarks ?? ''} onChange={(e) => setItem(i, { remarks: e.target.value })} aria-label={t('Remarks')} /></td>
                                        <td className="px-3 py-1.5"><Input className="min-w-28" lang="bn" disabled={locked} value={it.remarks_bn ?? ''} onChange={(e) => setItem(i, { remarks_bn: e.target.value })} aria-label={t('Remarks (Bangla)')} /></td>
                                        <td className="px-3 py-1.5">{!locked && <Button size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => form.setData('items', form.data.items.filter((_, n) => n !== i))}><Trash2 className="text-destructive" /></Button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-4 pb-3"><InputError message={errors.items ?? Object.entries(errors).find(([k]) => k.startsWith('items.'))?.[1]} /></div>
                </section>

                <FormSection title={t('Rules')} description={t('How subject grades combine into a result.')}>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {select('gpa_method', t('GPA method'))}
                        {usesGpa && form.data.rules.gpa_method === 'optional_bonus' && (
                            <Field label={t('Optional subject counts points above')} name="rules.optional_bonus_above" error={errors['rules.optional_bonus_above']}>
                                <Input id="rules.optional_bonus_above" inputMode="decimal" disabled={locked} value={form.data.rules.optional_bonus_above} onChange={(e) => setRule('optional_bonus_above', e.target.value)} />
                            </Field>
                        )}
                        {usesGpa && (
                            <Field label={t('Highest GPA')} name="rules.gpa_cap" hint={t('Leave empty for no cap.')} error={errors['rules.gpa_cap']}>
                                <Input id="rules.gpa_cap" inputMode="decimal" disabled={locked} value={form.data.rules.gpa_cap ?? ''} onChange={(e) => setRule('gpa_cap', e.target.value)} />
                            </Field>
                        )}
                        {usesGpa && select('gpa_decimals', t('GPA decimal places'))}
                        {usesGpa && select('gpa_rounding', t('GPA rounding'))}
                        {select('subject_mark_rounding', t('Round subject totals'))}
                        {select('merit_order', t('Merit order'))}
                    </div>
                    <div className="mt-5 grid gap-3 md:grid-cols-2">
                        {toggle('fail_if_any_subject_fails', t('Failing any compulsory subject fails the result'))}
                        {toggle('optional_fail_counts', t('Failing the optional subject also fails the result'))}
                        {usesGpa && toggle('gpa_zero_on_fail', t('Show GPA 0.00 when the result is fail'))}
                        {toggle('absent_is_fail', t('Absence in a subject counts as fail (otherwise the subject is left out)'))}
                        {toggle('rank_failed', t('Give failed students a merit position after everyone who passed'))}
                    </div>
                </FormSection>

                {scale.exams.length > 0 && (
                    <FormSection title={t('Used by')}>
                        <ul className="flex flex-wrap gap-2 text-sm">
                            {scale.exams.map((e) => <li key={e.id}><Link href={show(e.id)} className="inline-flex items-center gap-2 rounded-md border border-border/70 px-2 py-1 hover:bg-muted">{e.name}<StatusBadge status={examTone[e.status]} label={t('exam_' + e.status)} /></Link></li>)}
                        </ul>
                    </FormSection>
                )}

                {!locked && (
                    <div>
                        <Button onClick={() => form.put(ExamSetupController.updateScale.url(scale.id), { preserveScroll: true })} disabled={form.processing}>{form.processing && <Spinner />}{t('Save')}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

GradingScaleEdit.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Exam setup', href: setup() }] };
