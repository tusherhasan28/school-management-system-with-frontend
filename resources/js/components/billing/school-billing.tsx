import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { Plan, PlanFeatureValues, PlatformInvoice, Subscription } from '@/components/billing/shared';
import { InvoiceBadge, SubscriptionBadge, limitLabel } from '@/components/billing/shared';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/shared/fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showInvoice } from '@/routes/platform/invoices';
import { assign, cancel, extendTrial, invoice as manualInvoice, reactivate, suspend } from '@/routes/platform/schools/billing';

export type SchoolBilling = {
    subscription: Subscription | null;
    history: Subscription[];
    invoices: PlatformInvoice[];
    plans: Plan[];
    usage: Record<string, number>;
    limits: PlanFeatureValues | null;
};

/** Platform school page: subscription, usage against plan limits, invoices. */
export function SchoolBillingPanel({ schoolId, billing, canManage }: { schoolId: number; billing: SchoolBilling; canManage: boolean }) {
    const t = useT();
    const sub = billing.subscription;
    const [changing, setChanging] = useState(!sub);

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <section className="rounded-xl border border-border/70 bg-card p-5 lg:col-span-2">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-medium">{t('Subscription')}</h2>
                        {sub ? (
                            <p className="mt-1 text-sm text-muted-foreground">
                                {sub.plan?.name} · {t(sub.billing_cycle)} · {formatMoney(sub.amount, 'en')}
                                {sub.auto_renew ? '' : ` · ${t('no auto-renewal')}`}{sub.cancel_at_period_end ? ` · ${t('cancels at period end')}` : ''}
                            </p>
                        ) : (
                            <p className="mt-1 text-sm text-muted-foreground">{t('No subscription: this school is not limited and not billed.')}</p>
                        )}
                    </div>
                    {sub && <SubscriptionBadge status={sub.status} />}
                </div>
                {sub && (
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                        <div><dt className="text-muted-foreground">{t('Current period')}</dt><dd>{formatDate(sub.current_period_start)} – {formatDate(sub.current_period_end)}</dd></div>
                        {sub.trial_ends_at && <div><dt className="text-muted-foreground">{t('Trial ends')}</dt><dd>{formatDate(sub.trial_ends_at)}</dd></div>}
                        {sub.grace_ends_at && <div><dt className="text-muted-foreground">{t('Grace ends')}</dt><dd className="text-rose-700 dark:text-rose-400">{formatDate(sub.grace_ends_at)}</dd></div>}
                    </dl>
                )}
                {canManage && sub && (
                    <div className="mt-5 flex flex-wrap gap-2 border-t pt-4">
                        <Button size="sm" variant="outline" onClick={() => setChanging((v) => !v)}>{t('Change plan')}</Button>
                        {sub.status === 'trialing' && <ExtendTrial schoolId={schoolId} />}
                        {sub.status === 'suspended' ? <ActionButton url={reactivate.url(schoolId)} label={t('Reactivate')} /> : <ReasonAction url={suspend.url(schoolId)} label={t('Suspend')} />}
                        {!sub.cancel_at_period_end && <ActionButton url={cancel.url(schoolId)} data={{ at_period_end: true }} label={t('Cancel at period end')} variant="ghost" />}
                        <ActionButton url={cancel.url(schoolId)} data={{ at_period_end: false }} label={t('Cancel now')} variant="ghost" confirm={t('Cancel now? The school is closed today; its data is kept.')} />
                    </div>
                )}
                {canManage && changing && <AssignForm schoolId={schoolId} plans={billing.plans} current={sub} onDone={() => setChanging(false)} />}
            </section>

            <section className="rounded-xl border border-border/70 bg-card p-5">
                <h2 className="font-medium">{t('Usage')}</h2>
                <ul className="mt-3 space-y-3 text-sm">
                    {Object.entries(billing.usage).map(([key, used]) => {
                        const limit = billing.limits?.[key] as number | null | undefined;
                        const pct = typeof limit === 'number' && limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;

                        return (
                            <li key={key}>
                                <div className="flex justify-between gap-2"><span>{t(`feature_${key}`)}</span><span className="tabular-nums">{used} / {limitLabel(billing.limits ? limit ?? null : null, t)}</span></div>
                                <div className="mt-1 h-1.5 rounded-full bg-muted"><div className={cn('h-1.5 rounded-full', pct >= 90 ? 'bg-rose-500' : 'bg-primary')} style={{ width: `${pct}%` }} /></div>
                            </li>
                        );
                    })}
                </ul>
            </section>

            <section className="rounded-xl border border-border/70 bg-card p-5 lg:col-span-2">
                <h2 className="font-medium">{t('Invoices')}</h2>
                {billing.invoices.length === 0 ? <p className="mt-2 text-sm text-muted-foreground">{t('No invoices')}</p> : (
                    <ul className="mt-2 divide-y text-sm">
                        {billing.invoices.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <div><Link href={showInvoice(i.id)} className="font-medium hover:underline">{i.invoice_number}</Link><p className="text-muted-foreground">{i.description}</p></div>
                                <div className="flex items-center gap-3"><span className="tabular-nums">{formatMoney(i.total, 'en')}</span><InvoiceBadge invoice={i} /></div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {canManage && <ManualInvoice schoolId={schoolId} />}

            {billing.history.length > 0 && (
                <section className="rounded-xl border border-border/70 bg-card p-5 lg:col-span-3">
                    <h2 className="font-medium">{t('Earlier subscriptions')}</h2>
                    <ul className="mt-2 divide-y text-sm">
                        {billing.history.map((h) => (
                            <li key={h.id} className="flex flex-wrap justify-between gap-2 py-2">
                                <span>{h.plan?.name} · {t(h.billing_cycle)} · {formatDate(h.starts_at)} – {formatDate(h.ended_at)}</span>
                                <SubscriptionBadge status={h.status} />
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

function AssignForm({ schoolId, plans, current, onDone }: { schoolId: number; plans: Plan[]; current: Subscription | null; onDone: () => void }) {
    const t = useT();
    const form = useForm({ plan_id: String(current?.plan?.id ?? plans[0]?.id ?? ''), billing_cycle: current?.billing_cycle ?? 'monthly', trial: !current, auto_renew: true, notes: '' });
    const plan = plans.find((p) => String(p.id) === form.data.plan_id);
    const errors = form.errors as Record<string, string>;

    return (
        <form className="mt-4 grid gap-3 rounded-lg border p-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); form.post(assign.url(schoolId), { preserveScroll: true, onSuccess: onDone }); }}>
            <div className="grid gap-1.5"><Label htmlFor="plan">{t('Plan')}</Label>
                <select id="plan" className={selectClass} value={form.data.plan_id} onChange={(e) => form.setData('plan_id', e.target.value)}>
                    {plans.map((p) => <option key={p.id} value={p.id}>{p.name} — {formatMoney(p.price_monthly, 'en')}/{t('month')}</option>)}
                </select><InputError message={errors.plan_id} />
            </div>
            <div className="grid gap-1.5"><Label htmlFor="cycle">{t('Billing')}</Label>
                <select id="cycle" className={selectClass} value={form.data.billing_cycle} onChange={(e) => form.setData('billing_cycle', e.target.value as 'monthly' | 'yearly')}>
                    <option value="monthly">{t('monthly')}{plan ? ` — ${formatMoney(plan.price_monthly, 'en')}` : ''}</option>
                    <option value="yearly">{t('yearly')}{plan ? ` — ${formatMoney(plan.price_yearly, 'en')}` : ''}</option>
                </select>
            </div>
            <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.trial} disabled={!plan?.trial_days} onCheckedChange={(v) => form.setData('trial', v === true)} />{t('Start with the :days-day trial', { days: String(plan?.trial_days ?? 0) })}</label>
            <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.auto_renew} onCheckedChange={(v) => form.setData('auto_renew', v === true)} />{t('Renew automatically')}</label>
            <div className="grid gap-1.5 sm:col-span-2"><Label htmlFor="notes">{t('Note')}</Label><Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></div>
            <InputError message={errors.trial ?? errors.billing_cycle} />
            <p className="text-xs text-muted-foreground sm:col-span-2">{current ? t('The current subscription ends now and its unpaid invoices are voided.') : t('Without a trial, the first period is invoiced now.')}</p>
            <div className="sm:col-span-2"><Button type="submit" disabled={form.processing}>{form.processing && <Spinner />}{t('Save subscription')}</Button></div>
        </form>
    );
}

function ActionButton({ url, label, data = {}, variant = 'outline', confirm }: { url: string; label: string; data?: Record<string, boolean>; variant?: 'outline' | 'ghost'; confirm?: string }) {
    const form = useForm(data);

    return <Button size="sm" variant={variant} disabled={form.processing} onClick={() => (!confirm || window.confirm(confirm)) && form.post(url, { preserveScroll: true })}>{label}</Button>;
}

function ReasonAction({ url, label }: { url: string; label: string }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    return open ? (
        <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); form.post(url, { preserveScroll: true }); }}>
            <div><Input className="h-8 w-56" placeholder={t('Reason')} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} aria-label={t('Reason')} /><InputError message={form.errors.reason} /></div>
            <Button size="sm" variant="destructive" disabled={form.processing}>{label}</Button>
        </form>
    ) : <Button size="sm" variant="outline" onClick={() => setOpen(true)}>{label}</Button>;
}

function ExtendTrial({ schoolId }: { schoolId: number }) {
    const t = useT();
    const form = useForm({ days: '7' });

    return (
        <form className="flex items-center gap-1" onSubmit={(e) => { e.preventDefault(); form.post(extendTrial.url(schoolId), { preserveScroll: true }); }}>
            <Input className="h-8 w-16" inputMode="numeric" value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} aria-label={t('Days')} />
            <Button size="sm" variant="outline" disabled={form.processing}>{t('Extend trial')}</Button>
        </form>
    );
}

function ManualInvoice({ schoolId }: { schoolId: number }) {
    const t = useT();
    const form = useForm({ description: '', amount: '', due_on: '' });

    return (
        <form className="grid content-start gap-3 rounded-xl border border-border/70 bg-card p-5" onSubmit={(e) => { e.preventDefault(); form.post(manualInvoice.url(schoolId), { preserveScroll: true, onSuccess: () => form.reset() }); }}>
            <h2 className="font-medium">{t('Other invoice')}</h2>
            <p className="text-sm text-muted-foreground">{t('Setup fee, SMS pack, training…')}</p>
            <div className="grid gap-1.5"><Label htmlFor="mi-desc">{t('Description')}</Label><Input id="mi-desc" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /><InputError message={form.errors.description} /></div>
            <div className="grid gap-1.5"><Label htmlFor="mi-amt">{t('Amount (BDT)')}</Label><Input id="mi-amt" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /><InputError message={form.errors.amount} /></div>
            <div className="grid gap-1.5"><Label htmlFor="mi-due">{t('Due on')}</Label><Input id="mi-due" type="date" value={form.data.due_on} onChange={(e) => form.setData('due_on', e.target.value)} /><InputError message={form.errors.due_on} /></div>
            <Button type="submit" disabled={form.processing}>{t('Issue invoice')}</Button>
        </form>
    );
}
