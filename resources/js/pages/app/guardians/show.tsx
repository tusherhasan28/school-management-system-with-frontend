import { Form, Head, Link } from '@inertiajs/react';
import GuardianController from '@/actions/App/Modules/People/Http/Controllers/GuardianController';
import { FormSection, TextareaField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { PortalAccess } from '@/components/shared/portal-access';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/guardians';
import { destroy as portalDestroy, store as portalStore } from '@/routes/guardians/portal';
import { show as studentShow } from '@/routes/students';

type Props = {
    guardian: {
        id: number; full_name: string; name_bn: string | null; phone: string; alternate_phone: string | null; email: string | null;
        occupation: string | null; address: string | null; has_national_id: boolean;
        students: { id: number; full_name: string; name_bn: string | null; admission_no: string; status: string; relationship: string }[];
    };
    can: { update: boolean };
    portal: { status: string | null } | null;
};

export default function GuardianShow({ guardian, can, portal }: Props) {
    const t = useT();

    return (
        <>
            <Head title={guardian.full_name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={guardian.full_name} description={[guardian.name_bn, guardian.phone].filter(Boolean).join(' · ')} />
                <div className="grid gap-4 lg:grid-cols-3">
                    <Form {...GuardianController.update.form(guardian.id)} options={{ preserveScroll: true }} className="lg:col-span-2">
                        {({ processing, errors }) => (
                            <FormSection title={t('Details')}>
                                <fieldset disabled={!can.update} className="grid gap-4 sm:grid-cols-2">
                                    <TextField label={t('Name')} name="full_name" defaultValue={guardian.full_name} required error={errors.full_name} />
                                    <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" defaultValue={guardian.name_bn ?? ''} error={errors.name_bn} />
                                    <TextField label={t('Mobile')} name="phone" type="tel" defaultValue={guardian.phone} required error={errors.phone} />
                                    <TextField label={t('Alternate mobile')} name="alternate_phone" type="tel" defaultValue={guardian.alternate_phone ?? ''} error={errors.alternate_phone} />
                                    <TextField label={t('Email')} name="email" type="email" defaultValue={guardian.email ?? ''} error={errors.email} />
                                    <TextField label={t('Occupation')} name="occupation" defaultValue={guardian.occupation ?? ''} error={errors.occupation} />
                                    <TextField label={t('National ID')} name="national_id" inputMode="numeric" autoComplete="off" placeholder={guardian.has_national_id ? t('Saved — leave blank to keep') : ''} hint={t('Stored encrypted.')} error={errors.national_id} />
                                    <TextareaField label={t('Address')} name="address" defaultValue={guardian.address ?? ''} error={errors.address} fieldClassName="sm:col-span-2" />
                                </fieldset>
                                {can.update && <Button type="submit" className="mt-4" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>}
                            </FormSection>
                        )}
                    </Form>
                    <section className="rounded-xl border border-border/70 bg-card">
                        <h2 className="border-b border-border/70 px-5 py-3 font-medium">{t('Children')}</h2>
                        <ul className="divide-y divide-border/70">
                            {guardian.students.map((s) => (
                                <li key={s.id} className="flex items-center justify-between gap-2 px-5 py-3 text-sm">
                                    <div>
                                        <Link href={studentShow(s.id)} className="font-medium hover:underline">{s.full_name}</Link>
                                        <p className="text-muted-foreground">{t(s.relationship)} · {s.admission_no}</p>
                                    </div>
                                    <StatusBadge status={s.status === 'active' ? 'active' : 'archived'} label={t(s.status)} />
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
                {portal && (
                    <PortalAccess kind="parent" status={portal.status} hasEmail={!!guardian.email} inviteUrl={portalStore(guardian.id).url} disableUrl={portalDestroy(guardian.id).url} />
                )}
            </div>
        </>
    );
}

GuardianShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Guardians', href: index() }] };
