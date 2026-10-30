import { Form, Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import TimetableController from '@/actions/App/Modules/Teaching/Http/Controllers/TimetableController';
import { EmptyState } from '@/components/shared/empty-state';
import { SelectField, TextField, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import type { Period } from '@/components/shared/week-grid';
import { WeekGrid } from '@/components/shared/week-grid';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useT, weekdayName } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/timetable';
import type { AcademicClass, SessionOption } from '@/types';

type Props = {
    filters: { session: number | null; section: number | null };
    section: { id: number; name: string; class_id: number; class: string } | null;
    periods: (Period & { subject_id: number; staff_member_id: number | null })[];
    sessions: SessionOption[];
    classes: AcademicClass[];
    subjects: { id: number; name: string }[];
    teachers: { id: number; full_name: string }[];
    weekend: number[];
    can: { manage: boolean };
};

type Editing = { day: number; period?: Props['periods'][number] } | null;

export default function Timetable({ filters, section, periods, sessions, classes, subjects, teachers, weekend, can }: Props) {
    const t = useT();
    const { locale } = usePage().props;
    const [editing, setEditing] = useState<Editing>(null);
    const go = (q: Record<string, unknown>) => router.get(index.url(), { ...filters, ...q }, { preserveState: false });

    return (
        <>
            <Head title={t('Class timetable')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Class timetable')}
                    description={t('Clashes are blocked: a section, a teacher and a room can only be in one place at a time.')}
                    actions={
                        <>
                            <select className={selectClass + ' w-auto'} value={filters.session ?? ''} onChange={(e) => go({ session: e.target.value })} aria-label={t('Session')}>
                                {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                            <select className={selectClass + ' w-auto'} value={filters.section ?? ''} onChange={(e) => go({ section: e.target.value })} aria-label={t('Section')}>
                                <option value="">{t('Choose a section…')}</option>
                                {classes.map((c) => (
                                    <optgroup key={c.id} label={c.name}>
                                        {c.sections.map((s) => <option key={s.id} value={s.id}>{c.name} · {s.name}</option>)}
                                    </optgroup>
                                ))}
                            </select>
                        </>
                    }
                />

                {!section ? (
                    <EmptyState icon={CalendarRange} title={t('Choose a section to see its timetable')} />
                ) : (
                    <WeekGrid
                        periods={periods}
                        weekend={weekend}
                        render={(p) => (
                            <button className="w-full text-left disabled:cursor-default" disabled={!can.manage} onClick={() => setEditing({ day: p.day_of_week, period: p as Props['periods'][number] })}>
                                <p className="text-xs text-muted-foreground tabular-nums">{p.starts_at}–{p.ends_at}{p.room ? ` · ${p.room}` : ''}</p>
                                <p className="font-medium">{p.subject}</p>
                                <p className="text-xs text-muted-foreground">{p.teacher ?? t('No teacher')}</p>
                            </button>
                        )}
                        onEmptyDay={can.manage ? (day) => (
                            <button className="rounded-lg border border-dashed p-2 text-sm text-muted-foreground hover:text-foreground" onClick={() => setEditing({ day })}>
                                <Plus className="mr-1 inline size-3.5" />{t('Add period')}
                            </button>
                        ) : undefined}
                    />
                )}
            </div>

            {editing && section && (
                <Dialog open onOpenChange={(o) => !o && setEditing(null)}>
                    <DialogContent>
                        <DialogTitle>{editing.period ? t('Edit period') : t('Add period')} · {weekdayName(editing.day, locale, 'long')}</DialogTitle>
                        <Form
                            {...(editing.period ? TimetableController.update.form(editing.period.id) : TimetableController.store.form())}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setEditing(null)}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input type="hidden" name="academic_session_id" value={filters.session ?? ''} />
                                    <input type="hidden" name="section_id" value={section.id} />
                                    <input type="hidden" name="day_of_week" value={editing.day} />
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextField label={t('Starts')} name="starts_at" type="time" defaultValue={editing.period?.starts_at ?? '08:00'} required error={errors.starts_at} />
                                        <TextField label={t('Ends')} name="ends_at" type="time" defaultValue={editing.period?.ends_at ?? '08:45'} required error={errors.ends_at} />
                                        <SelectField label={t('Subject')} name="subject_id" options={subjects.map((s) => ({ value: s.id, label: s.name }))} defaultValue={editing.period?.subject_id} required error={errors.subject_id} hint={subjects.length ? undefined : t('Set class subjects first (Academics).')} />
                                        <SelectField label={t('Teacher')} name="staff_member_id" placeholder="—" options={teachers.map((s) => ({ value: s.id, label: s.full_name }))} defaultValue={editing.period?.staff_member_id ?? ''} error={errors.staff_member_id} />
                                        <TextField label={t('Room')} name="room" defaultValue={editing.period?.room ?? ''} error={errors.room} />
                                    </div>
                                    <DialogFooter className="gap-2">
                                        {editing.period && (
                                            <Button type="button" variant="ghost" className="mr-auto text-destructive" onClick={() => router.delete(TimetableController.destroy.url(editing.period!.id), { preserveScroll: true, onSuccess: () => setEditing(null) })}>
                                                <Trash2 />{t('Delete')}
                                            </Button>
                                        )}
                                        <Button type="button" variant="secondary" onClick={() => setEditing(null)}>{t('Cancel')}</Button>
                                        <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
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

Timetable.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Class timetable', href: index() }] };
