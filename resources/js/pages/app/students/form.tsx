import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import StudentController from '@/actions/App/Modules/People/Http/Controllers/StudentController';
import { FormSection, SelectField, TextareaField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/students';
import type { AcademicClass, SessionOption } from '@/types';

type Student = {
    id: number;
    admission_no: string;
    full_name: string;
    name_bn: string | null;
    gender: string;
    blood_group: string | null;
    religion: string | null;
    nationality: string | null;
    email: string | null;
    present_address: string | null;
    permanent_address: string | null;
    date_of_birth: string | null;
    admission_date: string | null;
    has_birth_registration_no: boolean;
};

type Props = {
    student: Student | null;
    sessions: SessionOption[];
    classes: AcademicClass[];
    currentSessionId: number | null;
};

export const relationships = ['father', 'mother', 'guardian', 'brother', 'sister', 'uncle', 'aunt', 'grandparent', 'other'];
const bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

export default function StudentForm({ student, sessions, classes, currentSessionId }: Props) {
    const t = useT();
    const creating = student === null;
    const [classId, setClassId] = useState('');
    const [guardians, setGuardians] = useState([0]);
    const sections = classes.find((c) => String(c.id) === classId)?.sections ?? [];

    const action = creating ? StudentController.store.form() : StudentController.update.form(student.id);
    const genders = [
        { value: 'male', label: t('Male') },
        { value: 'female', label: t('Female') },
        { value: 'other', label: t('Other') },
        { value: 'not_specified', label: t('Not specified') },
    ];

    return (
        <>
            <Head title={creating ? t('Admit student') : t('Edit :name', { name: student.full_name })} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={creating ? t('Admit student') : t('Edit :name', { name: student.full_name })}
                    description={creating ? t('Creates the student, links guardians and enrolls in the selected class in one step.') : student.admission_no}
                />

                <Form {...action} className="grid max-w-4xl gap-5" options={{ preserveScroll: true }}>
                    {({ processing, errors }) => (
                        <>
                            <FormSection title={t('Student')}>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField label={t('Full name (English)')} name="full_name" defaultValue={student?.full_name} required error={errors.full_name} />
                                    <TextField label={t('Full name (Bangla)')} name="name_bn" lang="bn" defaultValue={student?.name_bn ?? ''} error={errors.name_bn} />
                                    <TextField label={t('Date of birth')} name="date_of_birth" type="date" defaultValue={student?.date_of_birth ?? ''} error={errors.date_of_birth} />
                                    <SelectField label={t('Gender')} name="gender" options={genders} defaultValue={student?.gender ?? 'not_specified'} required error={errors.gender} />
                                    <SelectField label={t('Blood group')} name="blood_group" placeholder="—" options={bloodGroups.map((b) => ({ value: b, label: b }))} defaultValue={student?.blood_group ?? ''} error={errors.blood_group} />
                                    <TextField label={t('Religion')} name="religion" defaultValue={student?.religion ?? ''} error={errors.religion} />
                                    <TextField label={t('Student email')} name="email" type="email" autoComplete="off" defaultValue={student?.email ?? ''} hint={t('Needed only for a student portal login.')} error={errors.email} />
                                    <TextField
                                        label={t('Birth registration no.')}
                                        name="birth_registration_no"
                                        inputMode="numeric"
                                        autoComplete="off"
                                        placeholder={student?.has_birth_registration_no ? t('Saved — leave blank to keep') : ''}
                                        hint={t('Stored encrypted.')}
                                        error={errors.birth_registration_no}
                                    />
                                    {creating && <TextField label={t('Admission no.')} name="admission_no" hint={t('Leave blank to generate automatically.')} error={errors.admission_no} />}
                                    <TextField label={t('Admission date')} name="admission_date" type="date" defaultValue={student?.admission_date ?? ''} error={errors.admission_date} />
                                    <TextareaField label={t('Present address')} name="present_address" defaultValue={student?.present_address ?? ''} error={errors.present_address} fieldClassName="sm:col-span-2" />
                                    <TextareaField label={t('Permanent address')} name="permanent_address" defaultValue={student?.permanent_address ?? ''} error={errors.permanent_address} fieldClassName="sm:col-span-2" />
                                </div>
                            </FormSection>

                            {creating && (
                                <FormSection title={t('Enrollment')}>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <SelectField label={t('Session')} name="academic_session_id" options={sessions.map((s) => ({ value: s.id, label: s.name }))} defaultValue={currentSessionId ?? ''} required error={errors.academic_session_id} />
                                        <SelectField label={t('Class')} name="class_id" placeholder={t('Choose…')} options={classes.map((c) => ({ value: c.id, label: c.name }))} value={classId} onChange={(e) => setClassId(e.target.value)} required error={errors.class_id} />
                                        <SelectField label={t('Section')} name="section_id" placeholder="—" options={sections.map((s) => ({ value: s.id, label: s.name }))} disabled={!sections.length} error={errors.section_id} />
                                        <TextField label={t('Roll no.')} name="roll_no" error={errors.roll_no} />
                                    </div>
                                </FormSection>
                            )}

                            {creating && (
                                <FormSection title={t('Guardians')} description={t('An existing guardian with the same name and phone is reused, so siblings share one record.')}>
                                    <div className="grid gap-5">
                                        {guardians.map((key, i) => (
                                            <div key={key} className="grid gap-4 rounded-lg border border-dashed p-4 sm:grid-cols-2 lg:grid-cols-3">
                                                <TextField label={t('Name')} name={`guardians[${i}][full_name]`} id={`g${key}-name`} required error={errors[`guardians.${i}.full_name`]} />
                                                <TextField label={t('Mobile')} name={`guardians[${i}][phone]`} id={`g${key}-phone`} type="tel" placeholder="01XXXXXXXXX" required error={errors[`guardians.${i}.phone`]} />
                                                <SelectField label={t('Relationship')} name={`guardians[${i}][relationship]`} id={`g${key}-rel`} options={relationships.map((r) => ({ value: r, label: t(r) }))} defaultValue={i === 0 ? 'father' : 'mother'} required error={errors[`guardians.${i}.relationship`]} />
                                                <TextField label={t('Email')} name={`guardians[${i}][email]`} id={`g${key}-email`} type="email" error={errors[`guardians.${i}.email`]} />
                                                <TextField label={t('Occupation')} name={`guardians[${i}][occupation]`} id={`g${key}-occ`} error={errors[`guardians.${i}.occupation`]} />
                                                <input type="hidden" name={`guardians[${i}][is_primary]`} value={i === 0 ? '1' : '0'} />
                                                {i > 0 && (
                                                    <div className="flex items-end">
                                                        <Button type="button" variant="ghost" onClick={() => setGuardians((g) => g.filter((k) => k !== key))}>
                                                            <Trash2 />{t('Remove')}
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                        {errors.guardians && <p className="text-sm text-destructive">{errors.guardians}</p>}
                                        {guardians.length < 4 && (
                                            <Button type="button" variant="outline" className="justify-self-start" onClick={() => setGuardians((g) => [...g, Math.max(...g) + 1])}>
                                                <Plus />{t('Add another guardian')}
                                            </Button>
                                        )}
                                    </div>
                                </FormSection>
                            )}

                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {creating ? t('Admit student') : t('Save')}
                                </Button>
                                <Button type="button" variant="outline" asChild>
                                    <Link href={creating ? index() : show(student.id)}>{t('Cancel')}</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

StudentForm.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Students', href: index() },
    ],
};
