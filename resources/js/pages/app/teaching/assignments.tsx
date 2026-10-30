import { Form, Head, router } from '@inertiajs/react';
import { Trash2, UserCog } from 'lucide-react';
import { useState } from 'react';
import AssignmentController from '@/actions/App/Modules/Teaching/Http/Controllers/AssignmentController';
import { EmptyState } from '@/components/shared/empty-state';
import { FormSection, SelectField, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/assignments';
import type { AcademicClass, SessionOption } from '@/types';

type Row = { id: number; teacher: string; teacher_id: number; class: string; section: string | null; subject: string | null; assignment_type: string };

type Props = {
    assignments: Row[];
    filters: { session: number | null; teacher: number | null; class: number | null };
    sessions: SessionOption[];
    classes: AcademicClass[];
    subjects: { id: number; name: string }[];
    teachers: { id: number; full_name: string }[];
};

export default function Assignments({ assignments, filters, sessions, classes, subjects, teachers }: Props) {
    const t = useT();
    const [classId, setClassId] = useState('');
    const [type, setType] = useState('subject_teacher');
    const sections = classes.find((c) => String(c.id) === classId)?.sections ?? [];
    const go = (q: Record<string, string | number | null>) => {
        const query: Record<string, string | number> = {};
        for (const [k, v] of Object.entries({ ...filters, ...q } as Record<string, string | number | null>)) {
            if (v !== '' && v !== null) query[k] = v;
        }
        router.get(index.url(), query, { preserveState: true });
    };

    return (
        <>
            <Head title={t('Teacher assignments')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Teacher assignments')} description={t('Teachers only see attendance, homework and students for the classes and subjects assigned here.')} />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="grid content-start gap-3 lg:col-span-2">
                        <div className="flex flex-wrap gap-2">
                            <select className={selectClass + ' w-auto'} value={filters.session ?? ''} onChange={(e) => go({ session: e.target.value })} aria-label={t('Session')}>
                                {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                            <select className={selectClass + ' w-auto'} value={filters.teacher ?? ''} onChange={(e) => go({ teacher: e.target.value })} aria-label={t('Teacher')}>
                                <option value="">{t('All teachers')}</option>
                                {teachers.map((s) => <option key={s.id} value={s.id}>{s.full_name}</option>)}
                            </select>
                            <select className={selectClass + ' w-auto'} value={filters.class ?? ''} onChange={(e) => go({ class: e.target.value })} aria-label={t('Class')}>
                                <option value="">{t('All classes')}</option>
                                {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        {assignments.length === 0 ? (
                            <EmptyState icon={UserCog} title={t('No assignments yet')} />
                        ) : (
                            <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                                {assignments.map((a) => (
                                    <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                        <div>
                                            <p className="font-medium">{a.teacher}</p>
                                            <p className="text-muted-foreground">{a.class}{a.section ? ` · ${a.section}` : ` · ${t('All sections')}`}{a.subject ? ` · ${a.subject}` : ''}</p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant={a.assignment_type === 'class_teacher' ? 'default' : 'secondary'}>{t(a.assignment_type)}</Badge>
                                            <Button size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => router.delete(AssignmentController.destroy.url(a.id), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <Form {...AssignmentController.store.form()} options={{ preserveScroll: true }} className="self-start">
                        {({ processing, errors }) => (
                            <FormSection title={t('Assign a teacher')}>
                                <div className="grid gap-3">
                                    <input type="hidden" name="academic_session_id" value={filters.session ?? ''} />
                                    <SelectField label={t('Teacher')} name="staff_member_id" placeholder={t('Choose…')} options={teachers.map((s) => ({ value: s.id, label: s.full_name }))} required error={errors.staff_member_id} />
                                    <SelectField label={t('Role')} name="assignment_type" options={[{ value: 'subject_teacher', label: t('subject_teacher') }, { value: 'class_teacher', label: t('class_teacher') }]} value={type} onChange={(e) => setType(e.target.value)} error={errors.assignment_type} />
                                    <SelectField label={t('Class')} name="class_id" placeholder={t('Choose…')} options={classes.map((c) => ({ value: c.id, label: c.name }))} value={classId} onChange={(e) => setClassId(e.target.value)} required error={errors.class_id} />
                                    <SelectField label={t('Section')} name="section_id" placeholder={type === 'class_teacher' ? t('Choose…') : t('All sections')} options={sections.map((s) => ({ value: s.id, label: s.name }))} required={type === 'class_teacher'} error={errors.section_id} />
                                    {type === 'subject_teacher' && <SelectField label={t('Subject')} name="subject_id" placeholder={t('Choose…')} options={subjects.map((s) => ({ value: s.id, label: s.name }))} required error={errors.subject_id} />}
                                    <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Assign')}</Button>
                                </div>
                            </FormSection>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

Assignments.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Teacher assignments', href: index() }] };
