import { Head, router, useForm } from '@inertiajs/react';
import ClassSubjectController from '@/actions/App/Modules/Academics/Http/Controllers/ClassSubjectController';
import { EmptyState } from '@/components/shared/empty-state';
import { selectClass } from '@/components/shared/fields';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/academics';
import type { SessionOption } from '@/types';

type Row = { id: number; name: string; name_bn: string | null; code: string | null; assigned: boolean; full_marks: string; pass_marks: string; is_optional: boolean };

type Props = {
    schoolClass: { id: number; name: string; name_bn: string | null };
    session: { id: number; name: string } | null;
    sessions: SessionOption[];
    subjects: Row[];
    can: { manage: boolean };
};

export default function ClassSubjects({ schoolClass, session, sessions, subjects, can }: Props) {
    const t = useT();
    const form = useForm({ rows: subjects });

    const update = (id: number, patch: Partial<Row>) =>
        form.setData('rows', form.data.rows.map((r) => (r.id === id ? { ...r, ...patch } : r)));

    const save = () => {
        form.transform((data) => ({
            academic_session_id: session?.id,
            subjects: data.rows.filter((r) => r.assigned).map((r) => ({ subject_id: r.id, full_marks: r.full_marks, pass_marks: r.pass_marks, is_optional: r.is_optional })),
        }));
        form.put(ClassSubjectController.update.url(schoolClass.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('Subjects for :name', { name: schoolClass.name })} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Subjects for :name', { name: schoolClass.name })}
                    description={t('Tick the subjects this class studies and set full and pass marks. Exams will use these defaults.')}
                    actions={
                        <select
                            className={selectClass}
                            value={session?.id ?? ''}
                            aria-label={t('Session')}
                            onChange={(e) => router.get(ClassSubjectController.edit.url(schoolClass.id), { session: e.target.value })}
                        >
                            {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    }
                />

                {!session ? (
                    <EmptyState title={t('Create an academic session first')} />
                ) : subjects.length === 0 ? (
                    <EmptyState title={t('No subjects yet')} />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs font-medium text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-4 py-3">{t('Subject')}</th>
                                        <th className="px-4 py-3">{t('Full marks')}</th>
                                        <th className="px-4 py-3">{t('Pass marks')}</th>
                                        <th className="px-4 py-3">{t('Optional')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {form.data.rows.map((r, i) => (
                                        <tr key={r.id} className={r.assigned ? '' : 'text-muted-foreground'}>
                                            <td className="px-4 py-2">
                                                <label className="flex items-center gap-3">
                                                    <input type="checkbox" className="size-4 accent-primary" checked={r.assigned} disabled={!can.manage} onChange={(e) => update(r.id, { assigned: e.target.checked })} />
                                                    <span>{r.name}{r.name_bn && <span className="block text-xs text-muted-foreground">{r.name_bn}</span>}</span>
                                                </label>
                                            </td>
                                            <td className="px-4 py-2">
                                                <Input type="number" step="0.5" className="w-24" value={r.full_marks} disabled={!r.assigned || !can.manage} onChange={(e) => update(r.id, { full_marks: e.target.value })} aria-label={t('Full marks')} />
                                            </td>
                                            <td className="px-4 py-2">
                                                <Input type="number" step="0.5" className="w-24" value={r.pass_marks} disabled={!r.assigned || !can.manage} onChange={(e) => update(r.id, { pass_marks: e.target.value })} aria-label={t('Pass marks')} />
                                                <InputError message={(form.errors as Record<string, string>)[`subjects.${form.data.rows.filter((x) => x.assigned).findIndex((x) => x.id === r.id)}.pass_marks`]} />
                                            </td>
                                            <td className="px-4 py-2">
                                                <input type="checkbox" className="size-4 accent-primary" checked={r.is_optional} disabled={!r.assigned || !can.manage} onChange={(e) => update(r.id, { is_optional: e.target.checked })} aria-label={t('Optional')} data-row={i} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {can.manage && session && (
                    <div>
                        <Button onClick={save} disabled={form.processing}>{form.processing && <Spinner />}{t('Save')}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

ClassSubjects.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Academics', href: index() },
    ],
};
