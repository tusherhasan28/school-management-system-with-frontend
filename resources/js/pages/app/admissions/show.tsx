import { Form, Head, Link } from '@inertiajs/react';
import { Check, Eye, UserPlus, X } from 'lucide-react';
import { useState } from 'react';
import AdmissionController from '@/actions/App/Modules/Admissions/Http/Controllers/AdmissionController';
import { FormSection, SelectField, TextField } from '@/components/shared/fields';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/admissions';
import { show as studentShow } from '@/routes/students';
import type { AcademicClass, SessionOption } from '@/types';
import { admissionTone } from './index';

type Application = {
    id: number; application_no: string; applicant_name: string; applicant_name_bn: string | null; gender: string; guardian_name: string;
    guardian_relationship: string; guardian_phone: string; guardian_email: string | null; address: string | null; previous_school: string | null;
    status: string; review_note: string | null; student_id: number | null; applied_class_id: number | null; academic_session_id: number | null;
    date_of_birth: string | null; extra: Record<string, string>; class: string | null; session: string | null; reviewer: string | null;
    reviewed_at: string | null; submitted_at: string;
};

type Props = { application: Application; classes: AcademicClass[]; sessions: SessionOption[]; can: { convert: boolean } };

export default function AdmissionShow({ application: a, classes, sessions, can }: Props) {
    const t = useT();
    const [classId, setClassId] = useState(String(a.applied_class_id ?? ''));
    const sections = classes.find((c) => String(c.id) === classId)?.sections ?? [];

    const rows: [string, string | null][] = [
        [t('Name (Bangla)'), a.applicant_name_bn], [t('Date of birth'), a.date_of_birth], [t('Gender'), t(a.gender)],
        [t('Class applied for'), a.class], [t('Session'), a.session], [t('Previous school'), a.previous_school],
        [t('Guardian'), `${a.guardian_name} (${t(a.guardian_relationship)})`], [t('Guardian mobile'), a.guardian_phone],
        [t('Guardian email'), a.guardian_email], [t('Address'), a.address],
        [t("Father's name"), a.extra.father_name ?? null], [t("Mother's name"), a.extra.mother_name ?? null],
        [t('Religion'), a.extra.religion ?? null], [t('Blood group'), a.extra.blood_group ?? null],
        [t('Submitted'), formatDateTime(a.submitted_at)],
    ];

    const reviewButton = (status: string, label: string, icon: React.ReactNode, variant: 'default' | 'outline' | 'destructive' = 'outline') => (
        <Form {...AdmissionController.review.form(a.id)} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <>
                    <input type="hidden" name="status" value={status} />
                    <Button type="submit" variant={variant} disabled={processing}>{processing ? <Spinner /> : icon}{label}</Button>
                </>
            )}
        </Form>
    );

    return (
        <>
            <Head title={a.applicant_name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={a.applicant_name} description={a.application_no} actions={<StatusBadge status={admissionTone[a.status]} label={t(a.status)} />} />
                <div className="grid gap-4 lg:grid-cols-3">
                    <dl className="grid content-start gap-x-6 gap-y-4 rounded-xl border border-border/70 bg-card p-5 sm:grid-cols-2 lg:col-span-2">
                        {rows.filter(([, v]) => v).map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-sm text-muted-foreground">{label}</dt>
                                <dd className="mt-0.5 break-words">{value}</dd>
                            </div>
                        ))}
                    </dl>

                    <div className="grid content-start gap-4">
                        {['submitted', 'under_review', 'approved', 'rejected'].includes(a.status) && (
                            <FormSection title={t('Review')}>
                                <div className="flex flex-wrap gap-2">
                                    {a.status === 'submitted' && reviewButton('under_review', t('Mark under review'), <Eye />)}
                                    {a.status === 'rejected' && reviewButton('under_review', t('Reopen'), <Eye />)}
                                    {['submitted', 'under_review'].includes(a.status) && reviewButton('approved', t('Approve'), <Check />, 'default')}
                                </div>
                                {a.status !== 'rejected' && (
                                    <Form {...AdmissionController.review.form(a.id)} options={{ preserveScroll: true }} className="mt-4 grid gap-2">
                                        {({ processing, errors }) => (
                                            <>
                                                <input type="hidden" name="status" value="rejected" />
                                                <TextField label={t('Reason for rejection')} name="note" error={errors.note ?? errors.status} />
                                                <Button type="submit" variant="destructive" disabled={processing}>{processing ? <Spinner /> : <X />}{t('Reject')}</Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                                {a.review_note && <p className="mt-3 text-sm text-muted-foreground">{t('Note')}: {a.review_note}</p>}
                                {a.reviewer && <p className="mt-1 text-xs text-muted-foreground">{a.reviewer} · {formatDateTime(a.reviewed_at)}</p>}
                            </FormSection>
                        )}

                        {a.status === 'approved' && can.convert && (
                            <FormSection title={t('Admit as student')} description={t('Creates the student, links the guardian and enrolls in one step.')}>
                                <Form {...AdmissionController.convert.form(a.id)} className="grid gap-3">
                                    {({ processing, errors }) => (
                                        <>
                                            <SelectField label={t('Session')} name="academic_session_id" options={sessions.map((s) => ({ value: s.id, label: s.name }))} defaultValue={a.academic_session_id ?? sessions.find((s) => s.is_current)?.id} error={errors.academic_session_id} />
                                            <SelectField label={t('Class')} name="class_id" options={classes.map((c) => ({ value: c.id, label: c.name }))} value={classId} onChange={(e) => setClassId(e.target.value)} required error={errors.class_id} />
                                            <SelectField label={t('Section')} name="section_id" placeholder="—" options={sections.map((s) => ({ value: s.id, label: s.name }))} disabled={!sections.length} error={errors.section_id} />
                                            <TextField label={t('Roll no.')} name="roll_no" error={errors.roll_no} />
                                            <InputError message={errors.status} />
                                            <Button type="submit" disabled={processing}>{processing ? <Spinner /> : <UserPlus />}{t('Admit student')}</Button>
                                        </>
                                    )}
                                </Form>
                            </FormSection>
                        )}

                        {a.status === 'enrolled' && a.student_id && (
                            <Button asChild><Link href={studentShow(a.student_id)}>{t('Open student record')}</Link></Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

AdmissionShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Admissions', href: index() }] };
