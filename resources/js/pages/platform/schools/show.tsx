import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SchoolStatusController from '@/actions/App/Http/Controllers/Platform/SchoolStatusController';
import type { SchoolBilling } from '@/components/billing/school-billing';
import { SchoolBillingPanel } from '@/components/billing/school-billing';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { index } from '@/routes/platform/schools';
import type { PlatformSchool } from './index';

type Props = {
    school: PlatformSchool & {
        email: string | null;
        phone: string | null;
        district: string | null;
        suspension_reason: string | null;
        suspended_at: string | null;
    };
    billing: SchoolBilling | null;
    can: { manage: boolean; billing: boolean; support: boolean };
};

export default function SchoolShow({ school, billing, can }: Props) {
    const t = useT();
    const canManage = can.manage;
    const suspended = school.status === 'suspended';

    const details: [string, string | null][] = [
        [t('Subdomain'), school.url],
        [t('Type'), school.institution_type],
        [t('Email'), school.email],
        [t('Phone'), school.phone],
        [t('District'), school.district],
        [t('Created'), formatDateTime(school.created_at, 'en')],
    ];

    return (
        <>
            <Head title={school.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={school.name}
                    description={school.name_bn ?? undefined}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={index()}><ArrowLeft />{t('All schools')}</Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <dl className="grid gap-x-6 gap-y-4 rounded-xl border border-border/70 bg-card p-5 sm:grid-cols-2 lg:col-span-2">
                        <div>
                            <dt className="text-sm text-muted-foreground">{t('Status')}</dt>
                            <dd className="mt-1"><StatusBadge status={school.status} label={school.status_label} /></dd>
                        </div>
                        {details.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-sm text-muted-foreground">{label}</dt>
                                <dd className="mt-1 break-all">{value || '—'}</dd>
                            </div>
                        ))}
                        {suspended && (
                            <div className="sm:col-span-2">
                                <dt className="text-sm text-muted-foreground">{t('Suspension reason')}</dt>
                                <dd className="mt-1">{school.suspension_reason ?? '—'}</dd>
                            </div>
                        )}
                    </dl>

                    {canManage && (
                        <section className="rounded-xl border border-border/70 bg-card p-5">
                            <h2 className="font-medium">{suspended ? t('Reactivate school') : t('Suspend school')}</h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('Suspension blocks the portal and website. No data is deleted.')}
                            </p>
                            <Form {...SchoolStatusController.form(school.id)} options={{ preserveScroll: true }} className="mt-4 grid gap-3">
                                {({ processing, errors }) => (
                                    <>
                                        <input type="hidden" name="status" value={suspended ? 'active' : 'suspended'} />
                                        {!suspended && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="reason">{t('Reason')}</Label>
                                                <Input id="reason" name="reason" required />
                                                <InputError message={errors.reason} />
                                            </div>
                                        )}
                                        <InputError message={errors.status} />
                                        <Button type="submit" variant={suspended ? 'default' : 'destructive'} disabled={processing}>
                                            {processing && <Spinner />}
                                            {suspended ? t('Reactivate') : t('Suspend')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </section>
                    )}
                </div>
                {billing && <SchoolBillingPanel schoolId={school.id} billing={billing} canManage={can.billing} />}
            </div>
        </>
    );
}
