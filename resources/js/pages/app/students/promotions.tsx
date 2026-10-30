import { Form, Head, router } from '@inertiajs/react';
import { ArrowUpRight, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import PromotionController from '@/actions/App/Modules/People/Http/Controllers/PromotionController';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, selectClass } from '@/components/shared/fields';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as promotionsIndex } from '@/routes/promotions';
import { index as students } from '@/routes/students';
import type { AcademicClass, SessionOption } from '@/types';

type Props = {
    filters: { from_session?: number; class?: number; section?: number };
    sessions: SessionOption[];
    classes: AcademicClass[];
    students: { student_id: number; full_name: string; name_bn: string | null; admission_no: string; roll_no: string | null }[];
};

export default function Promotions({ filters, sessions, classes, students: rows }: Props) {
    const t = useT();
    const [selected, setSelected] = useState<number[]>(rows.map((r) => r.student_id));
    const [toClass, setToClass] = useState('');
    const fromClass = classes.find((c) => c.id === Number(filters.class));
    const toSections = classes.find((c) => String(c.id) === toClass)?.sections ?? [];
    const allSelected = useMemo(() => rows.length > 0 && selected.length === rows.length, [rows, selected]);

    const load = (next: Record<string, string>) =>
        router.get(promotionsIndex.url(), { ...filters, ...next }, { preserveState: false, replace: true });

    return (
        <>
            <Head title={t('Promote students')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Promote students')} description={t('Moves students to a class in another session. Previous enrollments stay in history.')} />

                <section className="grid gap-4 rounded-xl border border-border/70 bg-card p-5 sm:grid-cols-3">
                    <Field label={t('From session')} name="from_session">
                        <select id="from_session" className={selectClass} value={filters.from_session ?? ''} onChange={(e) => load({ from_session: e.target.value })}>
                            <option value="">{t('Choose…')}</option>
                            {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('From class')} name="class">
                        <select id="class" className={selectClass} value={filters.class ?? ''} onChange={(e) => load({ class: e.target.value, section: '' })}>
                            <option value="">{t('Choose…')}</option>
                            {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('Section')} name="section">
                        <select id="section" className={selectClass} value={filters.section ?? ''} onChange={(e) => load({ section: e.target.value })} disabled={!fromClass?.sections.length}>
                            <option value="">{t('All sections')}</option>
                            {fromClass?.sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                </section>

                {rows.length === 0 ? (
                    <EmptyState icon={Users} title={filters.class ? t('No enrolled students in this class') : t('Choose a session and class')} />
                ) : (
                    <Form {...PromotionController.store.form()} options={{ preserveScroll: true }} className="grid gap-4 lg:grid-cols-3">
                        {({ processing, errors }) => (
                            <>
                                <div className="overflow-hidden rounded-xl border border-border/70 bg-card lg:col-span-2">
                                    <label className="flex items-center gap-3 border-b border-border/70 bg-muted/50 px-4 py-3 text-sm font-medium">
                                        <input type="checkbox" className="size-4 accent-primary" checked={allSelected} onChange={(e) => setSelected(e.target.checked ? rows.map((r) => r.student_id) : [])} />
                                        {t(':count of :total selected', { count: selected.length, total: rows.length })}
                                    </label>
                                    <ul className="max-h-[28rem] divide-y divide-border/70 overflow-y-auto">
                                        {rows.map((r) => (
                                            <li key={r.student_id}>
                                                <label className="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm hover:bg-muted/30">
                                                    <input
                                                        type="checkbox"
                                                        name="student_ids[]"
                                                        value={r.student_id}
                                                        className="size-4 accent-primary"
                                                        checked={selected.includes(r.student_id)}
                                                        onChange={(e) => setSelected((s) => (e.target.checked ? [...s, r.student_id] : s.filter((id) => id !== r.student_id)))}
                                                    />
                                                    <span className="w-10 text-muted-foreground tabular-nums">{r.roll_no ?? '—'}</span>
                                                    <span className="flex-1">{r.full_name}</span>
                                                    <span className="font-mono text-xs text-muted-foreground">{r.admission_no}</span>
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                <section className="grid content-start gap-4 rounded-xl border border-border/70 bg-card p-5">
                                    <input type="hidden" name="from_session_id" value={filters.from_session} />
                                    <Field label={t('Action')} name="mode">
                                        <select id="mode" name="mode" className={selectClass} defaultValue="promoted">
                                            <option value="promoted">{t('Promote')}</option>
                                            <option value="repeated">{t('Repeat class')}</option>
                                        </select>
                                    </Field>
                                    <Field label={t('To session')} name="to_session_id" error={errors.to_session_id} required>
                                        <select id="to_session_id" name="to_session_id" className={selectClass} required>
                                            <option value="">{t('Choose…')}</option>
                                            {sessions.filter((s) => s.id !== Number(filters.from_session)).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                        </select>
                                    </Field>
                                    <Field label={t('To class')} name="to_class_id" error={errors.to_class_id} required>
                                        <select id="to_class_id" name="to_class_id" className={selectClass} value={toClass} onChange={(e) => setToClass(e.target.value)} required>
                                            <option value="">{t('Choose…')}</option>
                                            {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                        </select>
                                    </Field>
                                    <Field label={t('To section')} name="to_section_id" error={errors.to_section_id}>
                                        <select id="to_section_id" name="to_section_id" className={selectClass} disabled={!toSections.length}>
                                            <option value="">—</option>
                                            {toSections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                        </select>
                                    </Field>
                                    <InputError message={errors.student_ids} />
                                    <Button type="submit" disabled={processing || selected.length === 0}>
                                        {processing ? <Spinner /> : <ArrowUpRight />}
                                        {t('Move :count students', { count: selected.length })}
                                    </Button>
                                </section>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

Promotions.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Students', href: students() },
        { title: 'Promote students', href: promotionsIndex() },
    ],
};
