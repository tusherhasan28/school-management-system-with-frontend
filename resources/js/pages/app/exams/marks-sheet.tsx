import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, RotateCcw, Save, Send } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import MarkSheetController from '@/actions/App/Modules/Exams/Http/Controllers/MarkSheetController';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Field, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index } from '@/routes/marks';
import { sheetTone } from './show';

type Component = { id: number; name: string; name_bn: string | null; full_marks: string; pass_marks: string | null };
type Student = { id: number; roll: string | null; name: string; name_bn: string | null; admission_no: string; status: string; total: string | null; remarks: string | null; parts: Record<string, string>; saved: boolean };
type Row = { status: string; parts: Record<string, string>; remarks: string };

type Props = {
    sheet: {
        id: number; status: string; exam: { id: number; name: string; status: string }; class: string; section: string; subject: string; subject_type: string;
        full_marks: string; pass_marks: string; submitted: { by: string | null; at: string } | null; verified: { by: string | null; at: string } | null;
    };
    components: Component[];
    students: Student[];
    can: { edit: boolean; submit: boolean; verify: boolean; return: boolean };
};

const statuses = ['present', 'absent', 'exempt'];

export default function MarkSheet({ sheet, components, students, can }: Props) {
    const t = useT();
    const { locale, errors: pageErrors } = usePage().props as { locale: string; errors: Record<string, string> };
    const form = useForm<{ rows: Record<number, Row>; submit: boolean }>({
        rows: Object.fromEntries(students.map((s) => [s.id, { status: s.status, parts: Object.fromEntries(components.map((c) => [c.id, s.parts[c.id] ? String(Number(s.parts[c.id])) : ''])), remarks: s.remarks ?? '' }])),
        submit: false,
    });
    const errors = form.errors as Record<string, string>;
    const rows = form.data.rows;
    const setRow = (id: number, patch: Partial<Row>) => form.setData('rows', { ...rows, [id]: { ...rows[id], ...patch } });

    const total = (r: Row) => (r.status !== 'present' ? null : components.reduce((sum, c) => sum + (Number(r.parts[c.id]) || 0), 0));
    const complete = (r: Row) => r.status !== 'present' || components.every((c) => r.parts[c.id] !== '');
    const outOfRange = (c: Component, v: string) => v !== '' && (Number.isNaN(Number(v)) || Number(v) < 0 || Number(v) > Number(c.full_marks));
    const missing = students.filter((s) => !complete(rows[s.id])).length;

    // Enter moves to the same column in the next row, like a spreadsheet.
    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>, row: number, col: number) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.querySelector<HTMLInputElement>(`[data-cell="${row + (e.shiftKey ? -1 : 1)}-${col}"]`)?.focus();
        }
    };

    const save = (submit: boolean) => {
        form.transform((data) => ({ ...data, submit }));
        form.post(MarkSheetController.save.url(sheet.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`${sheet.subject} · ${sheet.class} ${sheet.section}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={`${sheet.subject} · ${sheet.class} · ${sheet.section}`}
                    description={`${sheet.exam.name} · ${t('Full marks')} ${Number(sheet.full_marks)} · ${t('Pass marks')} ${Number(sheet.pass_marks)}${sheet.subject_type !== 'compulsory' ? ` · ${t('subject_type_' + sheet.subject_type)}` : ''}`}
                    actions={<StatusBadge status={sheetTone[sheet.status]} label={t('sheet_' + sheet.status)} />}
                />

                {(sheet.submitted || sheet.verified) && (
                    <p className="text-sm text-muted-foreground">
                        {sheet.submitted && t('Submitted by :by, :at', { by: sheet.submitted.by ?? '—', at: formatDateTime(sheet.submitted.at) })}
                        {sheet.verified && ` · ${t('Verified by :by, :at', { by: sheet.verified.by ?? '—', at: formatDateTime(sheet.verified.at) })}`}
                    </p>
                )}
                {sheet.exam.status !== 'marks_entry' && <p className="rounded-xl border border-border/70 bg-muted/50 p-3 text-sm">{t('Marks entry is closed for this exam.')}</p>}
                <InputError message={pageErrors.sheet ?? errors.marks ?? errors.rows} />

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="w-14 px-3 py-2">{t('Roll')}</th>
                                    <th className="px-3 py-2">{t('Student')}</th>
                                    <th className="px-3 py-2">{t('Status')}</th>
                                    {components.map((c) => (
                                        <th key={c.id} className="px-3 py-2">
                                            {locale === 'bn' && c.name_bn ? c.name_bn : c.name}
                                            <span className="ml-1 font-normal normal-case">/{Number(c.full_marks)}</span>
                                        </th>
                                    ))}
                                    <th className="px-3 py-2 text-right">{t('Total')}</th>
                                    <th className="px-3 py-2">{t('Remarks')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {students.map((s, i) => {
                                    const r = rows[s.id];
                                    const sum = total(r);
                                    const below = sum !== null && complete(r) && sum < Number(sheet.pass_marks);

                                    return (
                                        <tr key={s.id} className={cn(r.status === 'absent' && 'bg-rose-50/40 dark:bg-rose-500/5', r.status === 'exempt' && 'text-muted-foreground')}>
                                            <td className="px-3 py-1.5 tabular-nums">{s.roll ?? '—'}</td>
                                            <td className="px-3 py-1.5">
                                                <p className="font-medium">{s.name}</p>
                                                <p className="font-mono text-xs text-muted-foreground">{s.admission_no}</p>
                                            </td>
                                            <td className="px-3 py-1.5">
                                                {can.edit ? (
                                                    <select className={cn(selectClass, 'w-28')} value={r.status} onChange={(e) => setRow(s.id, { status: e.target.value })} aria-label={t('Status for :name', { name: s.name })}>
                                                        {statuses.map((st) => <option key={st} value={st}>{t('mark_' + st)}</option>)}
                                                    </select>
                                                ) : t('mark_' + r.status)}
                                            </td>
                                            {components.map((c, col) => (
                                                <td key={c.id} className="px-3 py-1.5">
                                                    {can.edit && r.status === 'present' ? (
                                                        <>
                                                            <Input
                                                                data-cell={`${i}-${col}`}
                                                                inputMode="decimal"
                                                                className={cn('w-20 text-right tabular-nums', outOfRange(c, r.parts[c.id]) && 'border-destructive')}
                                                                value={r.parts[c.id]}
                                                                onChange={(e) => setRow(s.id, { parts: { ...r.parts, [c.id]: e.target.value } })}
                                                                onKeyDown={(e) => onKeyDown(e, i, col)}
                                                                aria-label={`${s.name} ${c.name}`}
                                                                aria-invalid={outOfRange(c, r.parts[c.id]) || undefined}
                                                            />
                                                            <InputError message={errors[`rows.${s.id}.parts.${c.id}`]} />
                                                        </>
                                                    ) : (
                                                        <span className="tabular-nums">{r.status === 'present' ? r.parts[c.id] || '—' : '—'}</span>
                                                    )}
                                                </td>
                                            ))}
                                            <td className={cn('px-3 py-1.5 text-right font-medium tabular-nums', below && 'text-destructive')}>{sum === null ? '—' : complete(r) ? sum : `${sum}…`}</td>
                                            <td className="px-3 py-1.5">
                                                {can.edit ? <Input className="min-w-32" value={r.remarks} onChange={(e) => setRow(s.id, { remarks: e.target.value })} aria-label={t('Remarks for :name', { name: s.name })} /> : r.remarks || ''}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="flex flex-wrap items-center gap-2">
                    {can.edit && (
                        <>
                            <Button variant="outline" onClick={() => save(false)} disabled={form.processing}>{form.processing && <Spinner />}<Save />{t('Save draft')}</Button>
                            <ConfirmDialog
                                trigger={<Button disabled={form.processing || missing > 0}><Send />{t('Save and submit')}</Button>}
                                title={t('Submit this mark sheet?')}
                                description={t('You cannot change marks after submitting. A verifier can return the sheet if something needs fixing.')}
                                onConfirm={() => save(true)}
                            />
                        </>
                    )}
                    {can.verify && sheet.status === 'submitted' && <Button onClick={() => router.post(MarkSheetController.verify.url(sheet.id), {}, { preserveScroll: true })}><BadgeCheck />{t('Verify')}</Button>}
                    {can.return && <ReturnDialog sheetId={sheet.id} />}
                    {missing > 0 && <span className="text-sm text-muted-foreground">{t(':count students still need marks', { count: missing })}</span>}
                </div>
            </div>
        </>
    );
}

function ReturnDialog({ sheetId }: { sheetId: number }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button variant="ghost"><RotateCcw />{t('Return for changes')}</Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{t('Return for changes')}</DialogTitle>
                <DialogDescription>{t('The teacher can edit the marks again and must submit the sheet again.')}</DialogDescription>
                <Field label={t('Reason')} name="reason" required error={form.errors.reason}><Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                <Button disabled={form.processing} onClick={() => form.post(MarkSheetController.returnSheet.url(sheetId), { preserveScroll: true, onSuccess: () => setOpen(false) })}>{form.processing && <Spinner />}{t('Return for changes')}</Button>
            </DialogContent>
        </Dialog>
    );
}

MarkSheet.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Marks entry', href: index() }] };
