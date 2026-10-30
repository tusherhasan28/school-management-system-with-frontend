import { Form, Head, Link, useForm } from '@inertiajs/react';
import { CheckCheck, History, Lock, Pencil } from 'lucide-react';
import { useState } from 'react';
import AttendanceController from '@/actions/App/Modules/Attendance/Http/Controllers/AttendanceController';
import InputError from '@/components/input-error';
import { SelectField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index } from '@/routes/attendance';

type Student = { student_id: number; full_name: string; name_bn: string | null; roll_no: string | null; mark_id: number | null; status: string | null; remarks: string | null };

type Props = {
    section: { id: number; name: string; class: string };
    routine: { id: number; subject: string; starts_at: string } | null;
    date: string;
    closed: string | null;
    future: boolean;
    session: { id: number; finalized_at: string | null } | null;
    students: Student[];
    corrections: { id: number; mark_id: number; old_status: string; new_status: string; reason: string; by: string | null; at: string }[];
    can: { take: boolean; correct: boolean };
};

const statuses = ['present', 'absent', 'late', 'leave', 'half_day'] as const;
const short: Record<string, string> = { present: 'P', absent: 'A', late: 'L', leave: 'Lv', half_day: '½' };
const tone: Record<string, string> = {
    present: 'bg-emerald-600 text-white border-emerald-600',
    absent: 'bg-rose-600 text-white border-rose-600',
    late: 'bg-amber-500 text-white border-amber-500',
    leave: 'bg-sky-600 text-white border-sky-600',
    half_day: 'bg-violet-600 text-white border-violet-600',
};
const badge: Record<string, string> = { present: 'active', absent: 'suspended', late: 'pending', leave: 'trial', half_day: 'archived' };

export default function TakeAttendance({ section, routine, date, closed, future, session, students, corrections, can }: Props) {
    const t = useT();
    const finalized = Boolean(session?.finalized_at);
    const editable = can.take && !finalized && !closed && !future;
    const [correcting, setCorrecting] = useState<Student | null>(null);

    const form = useForm({
        marks: Object.fromEntries(students.map((s) => [s.student_id, { status: s.status ?? '', remarks: s.remarks ?? '' }])) as Record<number, { status: string; remarks: string }>,
    });

    const set = (id: number, patch: Partial<{ status: string; remarks: string }>) => form.setData('marks', { ...form.data.marks, [id]: { ...form.data.marks[id], ...patch } });
    const allPresent = () => form.setData('marks', Object.fromEntries(students.map((s) => [s.student_id, { ...form.data.marks[s.student_id], status: form.data.marks[s.student_id].status || 'present' }])));
    const counts = statuses.map((st) => ({ st, n: Object.values(form.data.marks).filter((m) => m.status === st).length }));
    const unmarked = Object.values(form.data.marks).filter((m) => !m.status).length;

    const submit = (finalize: boolean) => {
        form.transform((d) => ({
            section_id: section.id,
            date,
            routine_id: routine?.id ?? null,
            finalize,
            marks: Object.fromEntries(Object.entries(d.marks).filter(([, m]) => m.status).map(([id, m]) => [id, { status: m.status, remarks: m.remarks || null }])),
        }));
        form.post(AttendanceController.store.url(), { preserveScroll: true });
    };

    const title = `${section.class} · ${section.name}${routine ? ` · ${routine.subject} (${routine.starts_at})` : ''}`;

    return (
        <>
            <Head title={t('Attendance')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={title}
                    description={formatDate(date)}
                    actions={finalized ? <StatusBadge status="active" label={t('finalized')} /> : session ? <StatusBadge status="trial" label={t('draft')} /> : null}
                />

                {(closed || future) && <p className="rounded-xl border border-amber-500/30 bg-amber-50 p-4 text-sm dark:bg-amber-500/10">{closed ?? t('Attendance cannot be taken for a future date.')}</p>}
                {finalized && <p className="flex items-center gap-2 text-sm text-muted-foreground"><Lock className="size-4" />{t('Finalized :at. Changes are made as corrections with a reason.', { at: formatDateTime(session?.finalized_at) })}</p>}

                {students.length === 0 ? (
                    <p className="rounded-xl border bg-card p-6 text-sm text-muted-foreground">{t('No students are enrolled in this section on this date.')}</p>
                ) : (
                    <>
                        {editable && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button variant="outline" size="sm" onClick={allPresent}><CheckCheck />{t('Mark the rest present')}</Button>
                                <div className="ml-auto flex flex-wrap gap-1.5 text-xs">
                                    {counts.map(({ st, n }) => <span key={st} className="rounded-full border px-2 py-0.5">{t(st)}: <b className="tabular-nums">{n}</b></span>)}
                                    {unmarked > 0 && <span className="rounded-full border border-dashed px-2 py-0.5">{t('Unmarked')}: <b>{unmarked}</b></span>}
                                </div>
                            </div>
                        )}

                        <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                            {students.map((s) => {
                                const mark = form.data.marks[s.student_id];

                                return (
                                    <li key={s.student_id} className="grid gap-2 px-4 py-3 sm:grid-cols-[3rem_1fr_auto] sm:items-center">
                                        <span className="text-sm text-muted-foreground tabular-nums">{s.roll_no ?? '—'}</span>
                                        <div>
                                            <p className="font-medium">{s.full_name}</p>
                                            {s.name_bn && <p className="text-sm text-muted-foreground">{s.name_bn}</p>}
                                            {editable && mark.status && mark.status !== 'present' && (
                                                <Input value={mark.remarks} onChange={(e) => set(s.student_id, { remarks: e.target.value })} placeholder={t('Note (optional)')} className="mt-2 h-8 max-w-xs" aria-label={t('Note (optional)')} />
                                            )}
                                        </div>
                                        {editable ? (
                                            <div role="radiogroup" aria-label={s.full_name} className="flex gap-1">
                                                {statuses.map((st) => (
                                                    <button
                                                        key={st}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={mark.status === st}
                                                        title={t(st)}
                                                        onClick={() => set(s.student_id, { status: st })}
                                                        className={cn('h-9 min-w-10 rounded-md border px-2 text-sm font-medium transition-colors', mark.status === st ? tone[st] : 'bg-background hover:bg-muted')}
                                                    >
                                                        {short[st]}
                                                        <span className="sr-only"> {t(st)}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                {s.status ? <StatusBadge status={badge[s.status]} label={t(s.status)} /> : <span className="text-sm text-muted-foreground">—</span>}
                                                {finalized && can.correct && s.mark_id && (
                                                    <Button size="icon" variant="ghost" aria-label={t('Correct :name', { name: s.full_name })} onClick={() => setCorrecting(s)}><Pencil /></Button>
                                                )}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>

                        <InputError message={form.errors.marks ?? (form.errors as Record<string, string>).attendance ?? (form.errors as Record<string, string>).date} />

                        {editable && (
                            <div className="sticky bottom-4 flex flex-wrap gap-2 rounded-xl border bg-background/95 p-3 shadow-sm backdrop-blur">
                                <Button variant="outline" onClick={() => submit(false)} disabled={form.processing}>{form.processing && <Spinner />}{t('Save draft')}</Button>
                                <Button onClick={() => submit(true)} disabled={form.processing || unmarked > 0}>{form.processing && <Spinner />}<Lock />{t('Save and finalize')}</Button>
                                <Button variant="ghost" asChild className="ml-auto"><Link href={index({ query: { date } })}>{t('Back')}</Link></Button>
                            </div>
                        )}
                    </>
                )}

                {corrections.length > 0 && (
                    <section className="rounded-xl border border-border/70 bg-card">
                        <h2 className="flex items-center gap-2 border-b border-border/70 px-4 py-3 font-medium"><History className="size-4" />{t('Corrections')}</h2>
                        <ul className="divide-y divide-border/70 text-sm">
                            {corrections.map((c) => (
                                <li key={c.id} className="px-4 py-2.5">
                                    <span className="font-medium">{students.find((s) => s.mark_id === c.mark_id)?.full_name}</span>: {t(c.old_status)} → {t(c.new_status)}
                                    <span className="text-muted-foreground"> · {c.reason} · {c.by} · {formatDateTime(c.at)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {correcting?.mark_id && (
                <Dialog open onOpenChange={(o) => !o && setCorrecting(null)}>
                    <DialogContent>
                        <DialogTitle>{t('Correct attendance')}</DialogTitle>
                        <DialogDescription>{correcting.full_name} · {t(correcting.status ?? '')}</DialogDescription>
                        <Form {...AttendanceController.correct.form(correcting.mark_id)} options={{ preserveScroll: true }} onSuccess={() => setCorrecting(null)} className="grid gap-4">
                            {({ processing, errors }) => (
                                <>
                                    <SelectField label={t('New status')} name="status" options={statuses.map((st) => ({ value: st, label: t(st) }))} defaultValue={correcting.status ?? 'present'} error={errors.status} />
                                    <TextField label={t('Reason')} name="reason" required minLength={3} error={errors.reason} placeholder={t('e.g. arrived with a medical note')} />
                                    <DialogFooter>
                                        <Button type="button" variant="secondary" onClick={() => setCorrecting(null)}>{t('Cancel')}</Button>
                                        <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save correction')}</Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

TakeAttendance.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Attendance', href: index() }] };
