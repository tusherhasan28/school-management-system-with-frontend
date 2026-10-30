import { Form, Head, Link, router } from '@inertiajs/react';
import { Pencil, Phone, Plus, Star, X } from 'lucide-react';
import { useState } from 'react';
import StudentController from '@/actions/App/Modules/People/Http/Controllers/StudentController';
import { SelectField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { show as guardianShow } from '@/routes/guardians';
import { edit, index } from '@/routes/students';
import { relationships } from './form';

type Props = {
    student: {
        id: number;
        admission_no: string;
        full_name: string;
        name_bn: string | null;
        gender: string;
        blood_group: string | null;
        religion: string | null;
        nationality: string | null;
        present_address: string | null;
        permanent_address: string | null;
        status: string;
        date_of_birth: string | null;
        admission_date: string | null;
        birth_registration_no: string | null;
        guardians: { id: number; full_name: string; name_bn: string | null; phone: string; email: string | null; occupation: string | null; relationship: string; is_primary: boolean }[];
        enrollments: { id: number; session: string; class: string; section: string | null; roll_no: string | null; status: string; enrolled_at: string; ended_at: string | null; end_reason: string | null }[];
    };
    can: { update: boolean; changeStatus: boolean; manageGuardians: boolean };
};

export default function StudentShow({ student, can }: Props) {
    const t = useT();
    const [linking, setLinking] = useState(false);
    const [ending, setEnding] = useState(false);

    const details: [string, string | null][] = [
        [t('Date of birth'), student.date_of_birth],
        [t('Gender'), t(student.gender)],
        [t('Blood group'), student.blood_group],
        [t('Religion'), student.religion],
        [t('Nationality'), student.nationality],
        [t('Birth registration no.'), student.birth_registration_no],
        [t('Admission date'), student.admission_date],
        [t('Present address'), student.present_address],
        [t('Permanent address'), student.permanent_address],
    ];

    return (
        <>
            <Head title={student.full_name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={student.full_name}
                    description={[student.name_bn, `${t('Admission no.')} ${student.admission_no}`].filter(Boolean).join(' · ')}
                    actions={
                        <>
                            <StatusBadge status={student.status === 'active' ? 'active' : 'archived'} label={t(student.status)} />
                            {can.changeStatus && student.status === 'active' && (
                                <Button variant="outline" onClick={() => setEnding(true)}>{t('Transfer / withdraw / graduate')}</Button>
                            )}
                            {can.update && (
                                <Button asChild><Link href={edit(student.id)}><Pencil />{t('Edit')}</Link></Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <dl className="grid gap-x-6 gap-y-4 rounded-xl border border-border/70 bg-card p-5 sm:grid-cols-2 lg:col-span-2">
                        {details.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-sm text-muted-foreground">{label}</dt>
                                <dd className="mt-0.5 break-words">{value || '—'}</dd>
                            </div>
                        ))}
                    </dl>

                    <section className="rounded-xl border border-border/70 bg-card">
                        <header className="flex items-center justify-between border-b border-border/70 px-5 py-3">
                            <h2 className="font-medium">{t('Guardians')}</h2>
                            {can.manageGuardians && (
                                <Button size="sm" variant="ghost" onClick={() => setLinking(true)}><Plus />{t('Add')}</Button>
                            )}
                        </header>
                        <ul className="divide-y divide-border/70">
                            {student.guardians.map((g) => (
                                <li key={g.id} className="flex items-start justify-between gap-2 px-5 py-3 text-sm">
                                    <div>
                                        <Link href={guardianShow(g.id)} className="font-medium hover:underline">{g.full_name}</Link>
                                        <div className="mt-0.5 flex flex-wrap items-center gap-2 text-muted-foreground">
                                            <span>{t(g.relationship)}</span>
                                            {g.is_primary && <Badge variant="secondary"><Star className="size-3" />{t('Primary')}</Badge>}
                                        </div>
                                        <a href={`tel:${g.phone}`} className="mt-1 inline-flex items-center gap-1 text-muted-foreground hover:text-foreground">
                                            <Phone className="size-3.5" />{g.phone}
                                        </a>
                                    </div>
                                    {can.manageGuardians && student.guardians.length > 1 && (
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={t('Remove :name', { name: g.full_name })}
                                            onClick={() => router.delete(StudentController.unlinkGuardian.url({ student: student.id, guardian: g.id }), { preserveScroll: true })}
                                        >
                                            <X />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                <section className="rounded-xl border border-border/70 bg-card">
                    <h2 className="border-b border-border/70 px-5 py-3 font-medium">{t('Enrollment history')}</h2>
                    <ol className="divide-y divide-border/70">
                        {student.enrollments.map((e) => (
                            <li key={e.id} className="grid gap-1 px-5 py-3 text-sm sm:grid-cols-4 sm:items-center">
                                <span className="font-medium">{e.session}</span>
                                <span>{e.class}{e.section ? ` · ${e.section}` : ''}{e.roll_no ? ` · ${t('Roll')} ${e.roll_no}` : ''}</span>
                                <span><StatusBadge status={e.status === 'enrolled' ? 'active' : 'archived'} label={t(e.status)} /></span>
                                <span className="text-muted-foreground">{e.enrolled_at}{e.ended_at ? ` → ${e.ended_at}` : ''}{e.end_reason ? ` · ${e.end_reason}` : ''}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>

            <Dialog open={linking} onOpenChange={setLinking}>
                <DialogContent>
                    <DialogTitle>{t('Add guardian')}</DialogTitle>
                    <DialogDescription>{t('If a guardian with this name and phone exists, they are linked instead of duplicated.')}</DialogDescription>
                    <Form {...StudentController.linkGuardian.form(student.id)} options={{ preserveScroll: true }} onSuccess={() => setLinking(false)} className="grid gap-4">
                        {({ processing, errors }) => (
                            <>
                                <TextField label={t('Name')} name="full_name" required error={errors.full_name} />
                                <TextField label={t('Mobile')} name="phone" type="tel" placeholder="01XXXXXXXXX" required error={errors.phone} />
                                <SelectField label={t('Relationship')} name="relationship" options={relationships.map((r) => ({ value: r, label: t(r) }))} error={errors.relationship} />
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="is_primary" value="1" className="size-4 accent-primary" />
                                    {t('Make primary guardian')}
                                </label>
                                <DialogFooter>
                                    <Button type="button" variant="secondary" onClick={() => setLinking(false)}>{t('Cancel')}</Button>
                                    <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog open={ending} onOpenChange={setEnding}>
                <DialogContent>
                    <DialogTitle>{t('End studies')}</DialogTitle>
                    <DialogDescription>{t('The current enrollment is closed and kept in history. The record is not deleted.')}</DialogDescription>
                    <Form {...StudentController.changeStatus.form(student.id)} options={{ preserveScroll: true }} onSuccess={() => setEnding(false)} className="grid gap-4">
                        {({ processing, errors }) => (
                            <>
                                <SelectField label={t('Outcome')} name="outcome" options={['transferred', 'withdrawn', 'graduated'].map((o) => ({ value: o, label: t(o) }))} error={errors.outcome} />
                                <TextField label={t('Reason')} name="reason" error={errors.reason} />
                                <TextField label={t('Date')} name="date" type="date" error={errors.date} />
                                <DialogFooter>
                                    <Button type="button" variant="secondary" onClick={() => setEnding(false)}>{t('Cancel')}</Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>{processing && <Spinner />}{t('Confirm')}</Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

StudentShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Students', href: index() },
    ],
};
