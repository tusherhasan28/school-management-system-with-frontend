import { Head, useForm } from '@inertiajs/react';
import { CheckCheck, Paperclip } from 'lucide-react';
import HomeworkController from '@/actions/App/Modules/Homework/Http/Controllers/HomeworkController';
import { selectClass } from '@/components/shared/fields';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { attachment, index } from '@/routes/homework';
import { homeworkTone } from './index';

type Submission = { student_id: number; full_name: string; admission_no: string; status: string; marks: string | null; feedback: string | null };

type Props = {
    homework: {
        id: number; title: string; instructions: string | null; status: string; attachment_name: string | null; max_marks: string | null;
        class: string; section: string | null; subject: string; teacher: string | null; assigned_on: string; due_at: string | null; has_attachment: boolean;
    };
    submissions: Submission[];
    can: { manage: boolean };
};

const statuses = ['pending', 'submitted', 'late', 'graded', 'missing'];

export default function HomeworkShow({ homework: h, submissions, can }: Props) {
    const t = useT();
    const form = useForm({ rows: Object.fromEntries(submissions.map((s) => [s.student_id, { status: s.status, marks: s.marks ?? '', feedback: s.feedback ?? '' }])) as Record<number, { status: string; marks: string; feedback: string }> });
    const set = (id: number, patch: Partial<{ status: string; marks: string; feedback: string }>) => form.setData('rows', { ...form.data.rows, [id]: { ...form.data.rows[id], ...patch } });
    const done = Object.values(form.data.rows).filter((r) => ['submitted', 'late', 'graded'].includes(r.status)).length;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head title={h.title} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={h.title}
                    description={[`${h.class}${h.section ? ` · ${h.section}` : ''}`, h.subject, h.teacher].filter(Boolean).join(' · ')}
                    actions={<StatusBadge status={homeworkTone[h.status]} label={t(h.status)} />}
                />
                <section className="grid gap-3 rounded-xl border border-border/70 bg-card p-5 text-sm">
                    <p className="text-muted-foreground">{t('Assigned on')} {formatDate(h.assigned_on)}{h.due_at ? ` · ${t('Due')} ${formatDateTime(h.due_at)}` : ''}{h.max_marks ? ` · ${t('Max marks')} ${h.max_marks}` : ''}</p>
                    {h.instructions && <p className="whitespace-pre-line">{h.instructions}</p>}
                    {h.has_attachment && <a href={attachment.url(h.id)} className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"><Paperclip className="size-4" />{h.attachment_name}</a>}
                </section>

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                        <h2 className="font-medium">{t('Submissions')} <span className="text-sm font-normal text-muted-foreground tabular-nums">{done}/{submissions.length}</span></h2>
                        {can.manage && (
                            <Button size="sm" variant="outline" onClick={() => form.setData('rows', Object.fromEntries(Object.entries(form.data.rows).map(([id, r]) => [id, r.status === 'pending' ? { ...r, status: 'submitted' } : r])))}>
                                <CheckCheck />{t('Mark pending as submitted')}
                            </Button>
                        )}
                    </header>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr><th className="px-4 py-2">{t('Student')}</th><th className="px-4 py-2">{t('Status')}</th><th className="px-4 py-2">{t('Marks')}</th><th className="px-4 py-2">{t('Feedback')}</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {submissions.map((s) => {
                                    const row = form.data.rows[s.student_id];

                                    return (
                                        <tr key={s.student_id}>
                                            <td className="px-4 py-2"><p className="font-medium">{s.full_name}</p><p className="font-mono text-xs text-muted-foreground">{s.admission_no}</p></td>
                                            <td className="px-4 py-2">
                                                {can.manage ? (
                                                    <select className={selectClass + ' w-36'} value={row.status} onChange={(e) => set(s.student_id, { status: e.target.value })} aria-label={t('Status')}>
                                                        {statuses.map((st) => <option key={st} value={st}>{t(st)}</option>)}
                                                    </select>
                                                ) : t(row.status)}
                                            </td>
                                            <td className="px-4 py-2">
                                                {can.manage ? <Input type="number" step="0.5" min={0} max={h.max_marks ?? undefined} className="w-24" value={row.marks} onChange={(e) => set(s.student_id, { marks: e.target.value })} aria-label={t('Marks')} /> : row.marks || '—'}
                                                <InputError message={errors[`rows.${s.student_id}.marks`]} />
                                            </td>
                                            <td className="px-4 py-2">{can.manage ? <Input value={row.feedback} onChange={(e) => set(s.student_id, { feedback: e.target.value })} aria-label={t('Feedback')} /> : row.feedback || '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
                {can.manage && submissions.length > 0 && (
                    <div>
                        <Button onClick={() => form.post(HomeworkController.grade.url(h.id), { preserveScroll: true })} disabled={form.processing}>{form.processing && <Spinner />}{t('Save')}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

HomeworkShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Homework', href: index() }] };
