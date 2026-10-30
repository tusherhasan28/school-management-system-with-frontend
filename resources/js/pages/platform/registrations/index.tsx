import { Form, Head, Link } from '@inertiajs/react';
import { Check, Inbox, X } from 'lucide-react';
import { useState } from 'react';
import RegistrationController from '@/actions/App/Http/Controllers/Platform/RegistrationController';
import { EmptyState } from '@/components/shared/empty-state';
import { TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/platform';
import { index } from '@/routes/platform/registrations';
import { show as schoolShow } from '@/routes/platform/schools';
import type { Paginated } from '@/types';

type Registration = {
    id: number; institution_name: string; institution_name_bn: string | null; requested_slug: string; institution_type: string;
    contact_name: string; email: string; phone: string; district: string | null; approx_students: number | null; status: string;
    rejection_reason: string | null; school_id: number | null; created_at: string;
};

type Props = { registrations: Paginated<Registration>; status: string; domain: string; pendingCount: number };

export default function Registrations({ registrations, status, domain, pendingCount }: Props) {
    const t = useT();
    const [acting, setActing] = useState<{ reg: Registration; mode: 'approve' | 'reject' } | null>(null);

    return (
        <>
            <Head title={t('Registrations')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Registrations')} description={t('Institutions asking to join. Approving creates the school (trial) and emails the contact an invitation.')} />
                <div className="flex gap-2">
                    {(['pending', 'approved', 'rejected'] as const).map((s) => (
                        <Link key={s} href={index({ query: { status: s } })} className={cn('rounded-full border px-3 py-1 text-sm', status === s ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>
                            {t(s)}{s === 'pending' && ` · ${pendingCount}`}
                        </Link>
                    ))}
                </div>
                {registrations.data.length === 0 ? (
                    <EmptyState icon={Inbox} title={t('Nothing here')} />
                ) : (
                    <ul className="grid gap-3">
                        {registrations.data.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border/70 bg-card p-4">
                                <div className="grid gap-1 text-sm">
                                    <p className="text-base font-medium">{r.institution_name} {r.institution_name_bn && <span className="font-normal text-muted-foreground">· {r.institution_name_bn}</span>}</p>
                                    <p className="font-mono text-xs">{r.requested_slug}.{domain}</p>
                                    <p className="text-muted-foreground">{t(r.institution_type)}{r.district && ` · ${r.district}`}{r.approx_students && ` · ${t('~:count students', { count: r.approx_students })}`}</p>
                                    <p>{r.contact_name} · {r.email} · {r.phone}</p>
                                    <p className="text-xs text-muted-foreground">{formatDateTime(r.created_at, 'en')}</p>
                                    {r.rejection_reason && <p className="text-destructive">{r.rejection_reason}</p>}
                                </div>
                                <div className="flex gap-2">
                                    {r.status === 'pending' && (
                                        <>
                                            <Button variant="outline" onClick={() => setActing({ reg: r, mode: 'reject' })}><X />{t('Reject')}</Button>
                                            <Button onClick={() => setActing({ reg: r, mode: 'approve' })}><Check />{t('Approve')}</Button>
                                        </>
                                    )}
                                    {r.school_id && <Button variant="outline" asChild><Link href={schoolShow(r.school_id)}>{t('Open school')}</Link></Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination page={registrations} />
            </div>

            {acting && (
                <Dialog open onOpenChange={(o) => !o && setActing(null)}>
                    <DialogContent>
                        <DialogTitle>{acting.mode === 'approve' ? t('Approve :name?', { name: acting.reg.institution_name }) : t('Reject :name?', { name: acting.reg.institution_name })}</DialogTitle>
                        <DialogDescription>{acting.mode === 'approve' ? t('You can change the web address before creating the school.') : t('The reason is kept on record.')}</DialogDescription>
                        <Form
                            {...(acting.mode === 'approve' ? RegistrationController.approve.form(acting.reg.id) : RegistrationController.reject.form(acting.reg.id))}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setActing(null)}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {acting.mode === 'approve' ? (
                                        <TextField label={t('Web address')} name="slug" defaultValue={acting.reg.requested_slug} hint={`…${domain}`} error={errors.slug ?? errors.status} />
                                    ) : (
                                        <TextField label={t('Reason')} name="reason" required error={errors.reason ?? errors.status} />
                                    )}
                                    <DialogFooter>
                                        <Button type="button" variant="secondary" onClick={() => setActing(null)}>{t('Cancel')}</Button>
                                        <Button type="submit" variant={acting.mode === 'approve' ? 'default' : 'destructive'} disabled={processing}>
                                            {processing && <Spinner />}{acting.mode === 'approve' ? t('Create school') : t('Reject')}
                                        </Button>
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

Registrations.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Registrations', href: index() }] };
