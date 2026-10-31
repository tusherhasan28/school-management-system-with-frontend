import { Head } from '@inertiajs/react';
import { Check, CreditCard, X } from 'lucide-react';
import type { PlatformInvoice, Subscription } from '@/components/billing/shared';
import { InvoiceBadge, SubscriptionBadge } from '@/components/billing/shared';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { formatDate, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { invoice as invoiceUrl, show } from '@/routes/subscription';

type Feature = { key: string; type: 'limit' | 'switch'; unit: string | null; value: number | boolean | null; used: number | null };
type Props = { subscription: Subscription | null; features: Feature[]; invoices: PlatformInvoice[]; instructions: string | null };

export default function SchoolSubscription({ subscription: sub, features, invoices, instructions }: Props) {
    const t = useT();
    const limits = features.filter((f) => f.type === 'limit');
    const switches = features.filter((f) => f.type === 'switch');
    const unpaid = invoices.some((i) => i.status === 'unpaid');

    return (
        <>
            <Head title={t('Subscription')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Subscription')} description={t('Your plan, what it includes and your invoices from the platform. To change the plan, open a support ticket.')} />

                {sub ? (
                    <section className="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border/70 bg-card p-5">
                        <div>
                            <p className="text-sm text-muted-foreground">{t('Plan')}</p>
                            <p className="text-xl font-semibold">{sub.plan?.name}</p>
                            <p className="text-sm text-muted-foreground">{formatMoney(sub.amount)} / {sub.billing_cycle === 'yearly' ? t('year') : t('month')}</p>
                        </div>
                        <dl className="grid gap-1 text-sm sm:text-right">
                            <dd><SubscriptionBadge status={sub.status} /></dd>
                            <dt className="text-muted-foreground">{sub.status === 'trialing' ? t('Trial ends') : t('Current period ends')}</dt>
                            <dd>{formatDate(sub.current_period_end)}</dd>
                            {sub.grace_ends_at && <dd className="text-rose-700 dark:text-rose-400">{t('Pay by :date to avoid suspension', { date: formatDate(sub.grace_ends_at) })}</dd>}
                            {sub.cancel_at_period_end && <dd className="text-amber-700">{t('Ends at the end of this period')}</dd>}
                        </dl>
                    </section>
                ) : (
                    <EmptyState icon={CreditCard} title={t('No subscription is set up for this school yet')} description={t('Nothing is limited. The platform team will contact you about a plan.')} />
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-xl border border-border/70 bg-card p-5">
                        <h2 className="font-medium">{t('Usage')}</h2>
                        <ul className="mt-3 space-y-3 text-sm">
                            {limits.map((f) => {
                                const limit = typeof f.value === 'number' ? f.value : null;
                                const pct = limit ? Math.min(100, Math.round(((f.used ?? 0) / limit) * 100)) : 0;

                                return (
                                    <li key={f.key}>
                                        <div className="flex justify-between gap-2"><span>{t(`feature_${f.key}`)}</span><span className="tabular-nums">{f.used} / {limit ?? t('Unlimited')}</span></div>
                                        <div className="mt-1 h-1.5 rounded-full bg-muted"><div className={cn('h-1.5 rounded-full', pct >= 90 ? 'bg-rose-500' : 'bg-primary')} style={{ width: `${pct}%` }} /></div>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                    <section className="rounded-xl border border-border/70 bg-card p-5">
                        <h2 className="font-medium">{t('Included')}</h2>
                        <ul className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                            {switches.map((f) => (
                                <li key={f.key} className="flex items-center gap-2">
                                    {f.value ? <Check className="size-4 text-emerald-600" /> : <X className="size-4 text-muted-foreground" />}
                                    <span className={cn(!f.value && 'text-muted-foreground line-through')}>{t(`feature_${f.key}`)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                <section className="rounded-xl border border-border/70 bg-card p-5">
                    <h2 className="font-medium">{t('Invoices')}</h2>
                    {unpaid && instructions && <p className="mt-3 rounded-lg bg-muted/60 p-3 text-sm whitespace-pre-line">{instructions}</p>}
                    {invoices.length === 0 ? <p className="mt-2 text-sm text-muted-foreground">{t('No invoices')}</p> : (
                        <ul className="mt-2 divide-y text-sm">
                            {invoices.map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div><a href={invoiceUrl.url(i.id)} className="font-medium hover:underline">{i.invoice_number}</a><p className="text-muted-foreground">{i.description} · {t('Due')} {formatDate(i.due_at)}</p></div>
                                    <div className="flex items-center gap-3"><span className="tabular-nums">{formatMoney(i.total)}</span><InvoiceBadge invoice={i} /></div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

SchoolSubscription.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Subscription', href: show() }] };
