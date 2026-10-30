import { Form, Head, Link } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import StaffController from '@/actions/App/Modules/People/Http/Controllers/StaffController';
import { FormSection, SelectField, TextareaField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/staff';

type Member = {
    id: number; staff_type: string; employee_no: string; full_name: string; name_bn: string | null; gender: string; phone: string | null;
    email: string | null; designation: string | null; department: string | null; qualification: string | null; address: string | null;
    status: string; date_of_birth: string | null; joining_date: string | null; login: { status: string; roles: string[] } | null;
};

type Props = { member: Member | null; roles: string[]; can?: { update: boolean } };

export default function StaffForm({ member, roles, can }: Props) {
    const t = useT();
    const creating = member === null;
    const editable = creating || can?.update;
    const action = creating ? StaffController.store.form() : StaffController.update.form(member.id);

    return (
        <>
            <Head title={creating ? t('Add staff') : member.full_name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={creating ? t('Add staff') : member.full_name} description={member ? `${member.employee_no}` : undefined} />
                <div className="grid max-w-5xl gap-4 lg:grid-cols-3">
                    <Form {...action} options={{ preserveScroll: true }} className="lg:col-span-2">
                        {({ processing, errors }) => (
                            <FormSection title={t('Profile')}>
                                <fieldset disabled={!editable} className="grid gap-4 sm:grid-cols-2">
                                    <SelectField label={t('Type')} name="staff_type" options={[{ value: 'teacher', label: t('teacher') }, { value: 'staff', label: t('staff') }]} defaultValue={member?.staff_type ?? 'teacher'} required error={errors.staff_type} />
                                    <TextField label={t('Employee no.')} name="employee_no" defaultValue={member?.employee_no ?? ''} hint={creating ? t('Leave blank to generate automatically.') : undefined} error={errors.employee_no} />
                                    <TextField label={t('Full name (English)')} name="full_name" defaultValue={member?.full_name} required error={errors.full_name} />
                                    <TextField label={t('Full name (Bangla)')} name="name_bn" lang="bn" defaultValue={member?.name_bn ?? ''} error={errors.name_bn} />
                                    <SelectField label={t('Gender')} name="gender" options={['male', 'female', 'other', 'not_specified'].map((g) => ({ value: g, label: t(g) }))} defaultValue={member?.gender ?? 'not_specified'} error={errors.gender} />
                                    <TextField label={t('Date of birth')} name="date_of_birth" type="date" defaultValue={member?.date_of_birth ?? ''} error={errors.date_of_birth} />
                                    <TextField label={t('Mobile')} name="phone" type="tel" placeholder="01XXXXXXXXX" defaultValue={member?.phone ?? ''} error={errors.phone} />
                                    <TextField label={t('Email')} name="email" type="email" defaultValue={member?.email ?? ''} hint={t('Needed to send a login invitation.')} error={errors.email} />
                                    <TextField label={t('Designation')} name="designation" defaultValue={member?.designation ?? ''} error={errors.designation} />
                                    <TextField label={t('Department')} name="department" defaultValue={member?.department ?? ''} error={errors.department} />
                                    <TextField label={t('Qualification')} name="qualification" defaultValue={member?.qualification ?? ''} error={errors.qualification} />
                                    <TextField label={t('Joining date')} name="joining_date" type="date" defaultValue={member?.joining_date ?? ''} error={errors.joining_date} />
                                    {!creating && <SelectField label={t('Status')} name="status" options={['active', 'on_leave', 'inactive'].map((s) => ({ value: s, label: t(s) }))} defaultValue={member.status} error={errors.status} />}
                                    {creating && roles.length > 0 && (
                                        <SelectField label={t('Give a login')} name="login_role" placeholder={t('No login for now')} options={roles.map((r) => ({ value: r, label: t(r) }))} hint={t('An invitation is emailed to set a password.')} error={errors.login_role} />
                                    )}
                                    <TextareaField label={t('Address')} name="address" defaultValue={member?.address ?? ''} error={errors.address} fieldClassName="sm:col-span-2" />
                                </fieldset>
                                {editable && (
                                    <div className="mt-4 flex gap-2">
                                        <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                                        <Button type="button" variant="outline" asChild><Link href={index()}>{t('Cancel')}</Link></Button>
                                    </div>
                                )}
                            </FormSection>
                        )}
                    </Form>

                    {!creating && (
                        <FormSection title={t('Login')} className="self-start">
                            {member.login ? (
                                <div className="grid gap-2 text-sm">
                                    <StatusBadge status={member.login.status === 'active' ? 'active' : 'pending'} label={t(member.login.status)} />
                                    <p className="text-muted-foreground">{member.login.roles.map((r) => t(r)).join(', ')}</p>
                                </div>
                            ) : roles.length > 0 ? (
                                <Form {...StaffController.createLogin.form(member.id)} options={{ preserveScroll: true }} className="grid gap-3">
                                    {({ processing, errors }) => (
                                        <>
                                            <SelectField label={t('Role')} name="role" options={roles.map((r) => ({ value: r, label: t(r) }))} defaultValue={member.staff_type === 'teacher' ? 'Teacher' : 'Receptionist'} error={errors.role} />
                                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                                            <Button type="submit" disabled={processing}>{processing ? <Spinner /> : <KeyRound />}{t('Send invitation')}</Button>
                                        </>
                                    )}
                                </Form>
                            ) : (
                                <p className="text-sm text-muted-foreground">{t('No login.')}</p>
                            )}
                        </FormSection>
                    )}
                </div>
            </div>
        </>
    );
}

StaffForm.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Teachers & staff', href: index() }] };
