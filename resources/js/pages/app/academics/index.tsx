import { Form, Head, Link, router } from '@inertiajs/react';
import { BookOpen, CalendarRange, Layers, ListChecks, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AcademicsController from '@/actions/App/Modules/Academics/Http/Controllers/AcademicsController';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { SelectField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index } from '@/routes/academics';
import { edit as classSubjects } from '@/routes/academics/class-subjects';

type Session = { id: number; name: string; start_date: string; end_date: string; is_current: boolean; status: string };
type Section = { id: number; name: string; capacity: number | null; room: string | null; students: number };
type SchoolClass = { id: number; name: string; name_bn: string | null; code: string | null; level_order: number; education_stage: string; is_active: boolean; students: number; sections: Section[] };
type Subject = { id: number; name: string; name_bn: string | null; code: string | null; subject_type: string; is_active: boolean };

type Props = { sessions: Session[]; classes: SchoolClass[]; subjects: Subject[]; can: { manage: boolean } };

type Editing =
    | { kind: 'session'; item: Session | null }
    | { kind: 'class'; item: SchoolClass | null }
    | { kind: 'section'; classId: number; item: Section | null }
    | { kind: 'subject'; item: Subject | null }
    | null;

const stages = ['early_years', 'primary', 'secondary', 'higher_secondary', 'college', 'madrasa', 'other'];
const subjectTypes = ['compulsory', 'optional', 'elective', 'practical', 'other'];

export default function AcademicsIndex({ sessions, classes, subjects, can }: Props) {
    const t = useT();
    const [tab, setTab] = useState<'classes' | 'sessions' | 'subjects'>('classes');
    const [editing, setEditing] = useState<Editing>(null);
    const del = (url: string) => router.delete(url, { preserveScroll: true });

    const tabs = [
        { key: 'classes', label: t('Classes & sections'), icon: Layers, count: classes.length },
        { key: 'sessions', label: t('Sessions'), icon: CalendarRange, count: sessions.length },
        { key: 'subjects', label: t('Subjects'), icon: BookOpen, count: subjects.length },
    ] as const;

    return (
        <>
            <Head title={t('Academics')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Academics')}
                    description={t('Your own class names, sections, subjects and academic years. Nothing is fixed by the system.')}
                    actions={can.manage && (
                        <Button onClick={() => setEditing(tab === 'sessions' ? { kind: 'session', item: null } : tab === 'subjects' ? { kind: 'subject', item: null } : { kind: 'class', item: null })}>
                            <Plus />{tab === 'sessions' ? t('Add session') : tab === 'subjects' ? t('Add subject') : t('Add class')}
                        </Button>
                    )}
                />

                <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-border">
                    {tabs.map(({ key, label, icon: Icon, count }) => (
                        <button
                            key={key}
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => setTab(key)}
                            className={cn('-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap transition-colors', tab === key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                        >
                            <Icon className="size-4" />{label}
                            <span className="rounded-full bg-muted px-1.5 text-xs tabular-nums">{count}</span>
                        </button>
                    ))}
                </div>

                {tab === 'classes' && (classes.length === 0 ? (
                    <EmptyState icon={Layers} title={t('No classes yet')} description={t('Use the setup wizard to add a ready-made list, or add classes one by one.')} />
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {classes.map((c) => (
                            <article key={c.id} className={cn('rounded-xl border border-border/70 bg-card p-4', !c.is_active && 'opacity-60')}>
                                <header className="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 className="font-medium">{c.name}</h3>
                                        <p className="text-sm text-muted-foreground">{[c.name_bn, t(c.education_stage)].filter(Boolean).join(' · ')}</p>
                                    </div>
                                    <div className="flex gap-0.5">
                                        <Button size="icon" variant="ghost" asChild aria-label={t('Subjects for :name', { name: c.name })}>
                                            <Link href={classSubjects(c.id)}><ListChecks /></Link>
                                        </Button>
                                        {can.manage && <Button size="icon" variant="ghost" aria-label={t('Edit :name', { name: c.name })} onClick={() => setEditing({ kind: 'class', item: c })}><Pencil /></Button>}
                                        {can.manage && c.students === 0 && (
                                            <ConfirmDialog
                                                trigger={<Button size="icon" variant="ghost" aria-label={t('Delete :name', { name: c.name })}><Trash2 className="text-destructive" /></Button>}
                                                title={t('Delete class?')} description={t('Its sections are deleted too.')} confirmLabel={t('Delete')} destructive
                                                onConfirm={() => del(AcademicsController.destroyClass.url(c.id))}
                                            />
                                        )}
                                    </div>
                                </header>
                                <p className="mt-2 text-sm">{t(':count students this session', { count: c.students })}</p>
                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    {c.sections.map((s) => (
                                        <span key={s.id} className="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-sm">
                                            {can.manage ? (
                                                <button className="hover:underline" onClick={() => setEditing({ kind: 'section', classId: c.id, item: s })}>{s.name}</button>
                                            ) : s.name}
                                            <span className="text-xs text-muted-foreground tabular-nums">{s.students}{s.capacity ? `/${s.capacity}` : ''}</span>
                                            {can.manage && s.students === 0 && (
                                                <button aria-label={t('Delete :name', { name: s.name })} className="text-muted-foreground hover:text-destructive" onClick={() => del(AcademicsController.destroySection.url(s.id))}>×</button>
                                            )}
                                        </span>
                                    ))}
                                    {can.manage && (
                                        <button className="rounded-md border border-dashed px-2 py-0.5 text-sm text-muted-foreground hover:text-foreground" onClick={() => setEditing({ kind: 'section', classId: c.id, item: null })}>
                                            + {t('Section')}
                                        </button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                ))}

                {tab === 'sessions' && (sessions.length === 0 ? (
                    <EmptyState icon={CalendarRange} title={t('No academic sessions yet')} />
                ) : (
                    <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                        {sessions.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <div className="flex items-center gap-2 font-medium">
                                        {s.name}
                                        {s.is_current && <Badge><Star className="size-3" />{t('Current')}</Badge>}
                                    </div>
                                    <p className="text-sm text-muted-foreground">{s.start_date} → {s.end_date}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge status={s.status === 'active' ? 'active' : s.status === 'planned' ? 'pending' : 'archived'} label={t(s.status)} />
                                    {can.manage && !s.is_current && (
                                        <Button size="sm" variant="outline" onClick={() => router.post(AcademicsController.makeCurrent.url(s.id), {}, { preserveScroll: true })}>{t('Make current')}</Button>
                                    )}
                                    {can.manage && <Button size="icon" variant="ghost" aria-label={t('Edit :name', { name: s.name })} onClick={() => setEditing({ kind: 'session', item: s })}><Pencil /></Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                ))}

                {tab === 'subjects' && (subjects.length === 0 ? (
                    <EmptyState icon={BookOpen} title={t('No subjects yet')} />
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {subjects.map((s) => (
                            <li key={s.id} className={cn('flex items-center justify-between gap-2 rounded-xl border border-border/70 bg-card px-4 py-3', !s.is_active && 'opacity-60')}>
                                <div>
                                    <p className="font-medium">{s.name} {s.code && <span className="font-mono text-xs text-muted-foreground">{s.code}</span>}</p>
                                    <p className="text-sm text-muted-foreground">{[s.name_bn, t(s.subject_type)].filter(Boolean).join(' · ')}</p>
                                </div>
                                {can.manage && (
                                    <div className="flex">
                                        <Button size="icon" variant="ghost" aria-label={t('Edit :name', { name: s.name })} onClick={() => setEditing({ kind: 'subject', item: s })}><Pencil /></Button>
                                        <Button size="icon" variant="ghost" aria-label={t('Delete :name', { name: s.name })} onClick={() => del(AcademicsController.destroySubject.url(s.id))}><Trash2 className="text-destructive" /></Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                ))}
            </div>

            <EditDialog editing={editing} onClose={() => setEditing(null)} nextOrder={Math.max(0, ...classes.map((c) => c.level_order)) + 1} />
        </>
    );
}

function EditDialog({ editing, onClose, nextOrder }: { editing: Editing; onClose: () => void; nextOrder: number }) {
    const t = useT();

    if (!editing) {
        return null;
    }

    const config = (() => {
        switch (editing.kind) {
            case 'session':
                return { title: editing.item ? t('Edit session') : t('Add session'), action: editing.item ? AcademicsController.updateSession.form(editing.item.id) : AcademicsController.storeSession.form() };
            case 'class':
                return { title: editing.item ? t('Edit class') : t('Add class'), action: editing.item ? AcademicsController.updateClass.form(editing.item.id) : AcademicsController.storeClass.form() };
            case 'section':
                return { title: editing.item ? t('Edit section') : t('Add section'), action: editing.item ? AcademicsController.updateSection.form(editing.item.id) : AcademicsController.storeSection.form(editing.classId) };
            case 'subject':
                return { title: editing.item ? t('Edit subject') : t('Add subject'), action: editing.item ? AcademicsController.updateSubject.form(editing.item.id) : AcademicsController.storeSubject.form() };
        }
    })();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{config.title}</DialogTitle>
                <Form {...config.action} options={{ preserveScroll: true }} onSuccess={onClose} className="grid gap-4">
                    {({ processing, errors }) => (
                        <>
                            {editing.kind === 'session' && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField label={t('Name')} name="name" placeholder="2026" defaultValue={editing.item?.name} required error={errors.name} fieldClassName="sm:col-span-2" />
                                    <TextField label={t('Starts')} name="start_date" type="date" defaultValue={editing.item?.start_date} required error={errors.start_date} />
                                    <TextField label={t('Ends')} name="end_date" type="date" defaultValue={editing.item?.end_date} required error={errors.end_date} />
                                    {editing.item && <SelectField label={t('Status')} name="status" options={['planned', 'active', 'completed', 'archived'].map((s) => ({ value: s, label: t(s) }))} defaultValue={editing.item.status} fieldClassName="sm:col-span-2" />}
                                </div>
                            )}
                            {editing.kind === 'class' && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField label={t('Name')} name="name" placeholder="Class 6" defaultValue={editing.item?.name} required error={errors.name} />
                                    <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" defaultValue={editing.item?.name_bn ?? ''} error={errors.name_bn} />
                                    <TextField label={t('Code')} name="code" defaultValue={editing.item?.code ?? ''} error={errors.code} />
                                    <TextField label={t('Order')} name="level_order" type="number" defaultValue={editing.item?.level_order ?? nextOrder} hint={t('Used to find the next class when promoting.')} required error={errors.level_order} />
                                    <SelectField label={t('Stage')} name="education_stage" options={stages.map((s) => ({ value: s, label: t(s) }))} defaultValue={editing.item?.education_stage ?? 'secondary'} error={errors.education_stage} />
                                    <SelectField label={t('Status')} name="is_active" options={[{ value: '1', label: t('active') }, { value: '0', label: t('inactive') }]} defaultValue={editing.item?.is_active === false ? '0' : '1'} />
                                </div>
                            )}
                            {editing.kind === 'section' && (
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <TextField label={t('Name')} name="name" placeholder="A" defaultValue={editing.item?.name} required error={errors.name} />
                                    <TextField label={t('Capacity')} name="capacity" type="number" defaultValue={editing.item?.capacity ?? ''} error={errors.capacity} />
                                    <TextField label={t('Room')} name="room" defaultValue={editing.item?.room ?? ''} error={errors.room} />
                                </div>
                            )}
                            {editing.kind === 'subject' && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField label={t('Name')} name="name" defaultValue={editing.item?.name} required error={errors.name} />
                                    <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" defaultValue={editing.item?.name_bn ?? ''} error={errors.name_bn} />
                                    <TextField label={t('Code')} name="code" defaultValue={editing.item?.code ?? ''} error={errors.code} />
                                    <SelectField label={t('Type')} name="subject_type" options={subjectTypes.map((s) => ({ value: s, label: t(s) }))} defaultValue={editing.item?.subject_type ?? 'compulsory'} error={errors.subject_type} />
                                    <SelectField label={t('Status')} name="is_active" options={[{ value: '1', label: t('active') }, { value: '0', label: t('inactive') }]} defaultValue={editing.item?.is_active === false ? '0' : '1'} />
                                </div>
                            )}
                            {Object.entries(errors).filter(([k]) => ['class', 'section', 'subject'].includes(k)).map(([k, v]) => <p key={k} className="text-sm text-destructive">{v}</p>)}
                            <DialogFooter>
                                <Button type="button" variant="secondary" onClick={onClose}>{t('Cancel')}</Button>
                                <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

AcademicsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Academics', href: index() },
    ],
};
