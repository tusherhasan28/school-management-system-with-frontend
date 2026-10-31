import { Form, Head, router, usePage } from '@inertiajs/react';
import { CreditCard, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import FeeSetupController from '@/actions/App/Modules/Finance/Http/Controllers/FeeSetupController';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, FormSection, SelectField, selectClass, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard as home } from '@/routes';
import { dashboard, setup } from '@/routes/fees';
import { periodLabel } from './shared';

type Category = { id: number; name: string; name_bn: string | null; description: string | null; is_active: boolean; label: string };
type Structure = {
    id: number; name: string; amount: string; frequency: string; start_period: string; applies_to: string; is_active: boolean;
    class_id: number | null; fee_category_id: number; category: string | null; class: string | null;
};
type Settings = { due_day: number; late_fee_enabled: boolean; late_fee_kind: string; late_fee_value: string; late_fee_grace_days: number; receipt_note: string | null };
type Gateway = { key: string; label: string; fields: { key: string; label: string; secret: boolean }[]; enabled: boolean; mode: string; configured: boolean };

type Props = {
    sessionId: number;
    session: { start: string; end: string } | null;
    sessions: { id: number; name: string }[];
    classes: { id: number; name: string }[];
    categories: Category[];
    structures: Structure[];
    settings: Settings;
    gateways: Gateway[];
};

const frequencies = ['monthly', 'quarterly', 'half_yearly', 'yearly', 'one_time'];

export default function FeeSetup({ sessionId, session, sessions, classes, categories, structures, settings, gateways }: Props) {
    const t = useT();
    const { locale } = usePage().props;

    return (
        <>
            <Head title={t('Fee setup')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Fee setup')}
                    description={t('Fee heads, what each class pays and when, late fees and online payments.')}
                    actions={
                        <select className={cn(selectClass, 'w-36')} value={sessionId} onChange={(e) => router.get(setup.url(), { session: e.target.value }, { preserveState: true })} aria-label={t('Session')}>
                            {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    }
                />

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                        <div>
                            <h2 className="font-medium">{t('Fee plans')}</h2>
                            <p className="text-sm text-muted-foreground">{t('Changes apply to invoices generated from now on; issued invoices keep their lines.')}</p>
                        </div>
                        {categories.length > 0 && session && <PlanDialog sessionId={sessionId} session={session} classes={classes} categories={categories} />}
                    </header>
                    {structures.length === 0 ? (
                        <div className="p-4"><EmptyState title={t('No fee plans for this session')} description={categories.length === 0 ? t('Add a fee head first.') : undefined} /></div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                    <tr><th className="px-4 py-2">{t('Name')}</th><th className="px-4 py-2">{t('Class')}</th><th className="px-4 py-2 text-right">{t('Amount')}</th><th className="px-4 py-2">{t('Billed')}</th><th className="px-4 py-2">{t('Who pays')}</th><th /></tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {structures.map((s) => (
                                        <tr key={s.id} className={cn(!s.is_active && 'text-muted-foreground')}>
                                            <td className="px-4 py-2.5"><p className="font-medium">{s.name}</p><p className="text-xs text-muted-foreground">{s.category}</p></td>
                                            <td className="px-4 py-2.5">{s.class ?? t('All classes')}</td>
                                            <td className="px-4 py-2.5 text-right font-medium tabular-nums">{formatMoney(s.amount)}</td>
                                            <td className="px-4 py-2.5">{t('frequency_' + s.frequency)} · {t('from :month', { month: periodLabel(s.start_period, locale) })}</td>
                                            <td className="px-4 py-2.5">{s.applies_to === 'class' ? t('Every student') : t('Assigned students')}{!s.is_active && <StatusBadge status="archived" label={t('inactive')} />}</td>
                                            <td className="px-4 py-2.5 text-right">{session && <PlanDialog sessionId={sessionId} session={session} classes={classes} categories={categories} plan={s} />}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <div className="grid gap-4 lg:grid-cols-3">
                    <FormSection title={t('Fee heads')} description={t('For example tuition, transport, exam, admission.')}>
                        <ul className="mb-4 divide-y divide-border/70 text-sm">
                            {categories.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-2 py-2">
                                    <span className={cn(!c.is_active && 'text-muted-foreground line-through')}>{c.label}</span>
                                    <Button size="sm" variant="ghost" onClick={() => router.put(FeeSetupController.updateCategory.url(c.id), { name: c.name, name_bn: c.name_bn, description: c.description, is_active: !c.is_active }, { preserveScroll: true })}>
                                        {c.is_active ? t('Deactivate') : t('Activate')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <Form {...FeeSetupController.storeCategory.form()} options={{ preserveScroll: true }} resetOnSuccess className="grid gap-3">
                            {({ processing, errors }) => (
                                <>
                                    <TextField label={t('Name')} name="name" required error={errors.name} />
                                    <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" error={errors.name_bn} />
                                    <Button type="submit" variant="outline" disabled={processing}>{processing && <Spinner />}{t('Add')}</Button>
                                </>
                            )}
                        </Form>
                    </FormSection>

                    <Form {...FeeSetupController.updateSettings.form()} options={{ preserveScroll: true }}>
                        {({ processing, errors }) => (
                            <FormSection title={t('Due dates and late fees')} className="h-full">
                                <div className="grid gap-3">
                                    <TextField label={t('Default due day of the month')} name="due_day" type="number" min={1} max={28} defaultValue={settings.due_day} error={errors.due_day} />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="hidden" name="late_fee_enabled" value="0" />
                                        <input type="checkbox" name="late_fee_enabled" value="1" defaultChecked={settings.late_fee_enabled} className="size-4 accent-primary" />
                                        {t('Add a late fee to overdue invoices')}
                                    </label>
                                    <div className="grid grid-cols-2 gap-3">
                                        <SelectField label={t('Late fee type')} name="late_fee_kind" defaultValue={settings.late_fee_kind} options={[{ value: 'fixed', label: t('Fixed amount') }, { value: 'percent', label: t('% of balance') }]} error={errors.late_fee_kind} />
                                        <TextField label={t('Late fee')} name="late_fee_value" inputMode="decimal" defaultValue={settings.late_fee_value} error={errors.late_fee_value} />
                                    </div>
                                    <TextField label={t('Days after the due date')} name="late_fee_grace_days" type="number" min={0} max={90} defaultValue={settings.late_fee_grace_days} hint={t('Added once per invoice, by the nightly job.')} error={errors.late_fee_grace_days} />
                                    <TextField label={t('Note printed on receipts')} name="receipt_note" defaultValue={settings.receipt_note ?? ''} error={errors.receipt_note} />
                                    <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                                </div>
                            </FormSection>
                        )}
                    </Form>

                    <FormSection title={t('Online payments')}>
                        {gateways.length === 0 ? (
                            <div className="flex gap-3 text-sm">
                                <CreditCard className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                                <p className="text-muted-foreground">{t('No online payment gateway is installed yet. bKash, Nagad, Rocket and SSLCommerz are added once your merchant account and the provider\'s official documentation are available. Until then, record mobile-banking payments manually with their transaction ID.')}</p>
                            </div>
                        ) : (
                            <ul className="grid gap-3">{gateways.map((g) => <GatewayForm key={g.key} gateway={g} />)}</ul>
                        )}
                    </FormSection>
                </div>
            </div>
        </>
    );
}

function PlanDialog({ sessionId, session, classes, categories, plan }: { sessionId: number; session: { start: string; end: string }; classes: { id: number; name: string }[]; categories: Category[]; plan?: Structure }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const action = plan ? FeeSetupController.updateStructure.form(plan.id) : FeeSetupController.storeStructure.form();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{plan ? <Button size="icon" variant="ghost" aria-label={t('Edit :name', { name: plan.name })}><Pencil /></Button> : <Button size="sm"><Plus />{t('Add fee plan')}</Button>}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{plan ? plan.name : t('Add fee plan')}</DialogTitle>
                <DialogDescription>{t('Monthly fees are billed every month from the first month; quarterly every 3 months; half-yearly every 6.')}</DialogDescription>
                <Form {...action} options={{ preserveScroll: true }} onSuccess={() => setOpen(false)} className="grid gap-3">
                    {({ processing, errors }) => (
                        <>
                            {!plan && <input type="hidden" name="academic_session_id" value={sessionId} />}
                            <TextField label={t('Name')} name="name" required defaultValue={plan?.name} placeholder={t('e.g. Monthly tuition')} error={errors.name} />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <SelectField label={t('Fee head')} name="fee_category_id" required defaultValue={plan?.fee_category_id} options={categories.filter((c) => c.is_active || c.id === plan?.fee_category_id).map((c) => ({ value: c.id, label: c.label }))} error={errors.fee_category_id} />
                                <SelectField label={t('Class')} name="class_id" defaultValue={plan?.class_id ?? ''} placeholder={t('All classes')} options={classes.map((c) => ({ value: c.id, label: c.name }))} error={errors.class_id} />
                                <TextField label={t('Amount (BDT)')} name="amount" required inputMode="decimal" defaultValue={plan?.amount} error={errors.amount} />
                                <SelectField label={t('Billed')} name="frequency" defaultValue={plan?.frequency ?? 'monthly'} options={frequencies.map((f) => ({ value: f, label: t('frequency_' + f) }))} error={errors.frequency} />
                                <Field label={t('First billing month')} name="start_period" required error={errors.start_period}>
                                    <Input id="start_period" name="start_period" type="month" min={session.start} max={session.end} defaultValue={plan?.start_period ?? session.start} required />
                                </Field>
                                <SelectField label={t('Who pays')} name="applies_to" defaultValue={plan?.applies_to ?? 'class'} options={[{ value: 'class', label: t('Every student') }, { value: 'assigned', label: t('Assigned students') }]} error={errors.applies_to} />
                            </div>
                            {plan && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="hidden" name="is_active" value="0" />
                                    <input type="checkbox" name="is_active" value="1" defaultChecked={plan.is_active} className="size-4 accent-primary" />{t('Active')}
                                </label>
                            )}
                            <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function GatewayForm({ gateway }: { gateway: Gateway }) {
    const t = useT();

    return (
        <li className="rounded-lg border border-border/70 p-3">
            <Form {...FeeSetupController.saveGateway.form(gateway.key)} options={{ preserveScroll: true }} className="grid gap-2">
                {({ processing, errors }) => (
                    <>
                        <p className="flex items-center justify-between font-medium">{gateway.label}<StatusBadge status={gateway.enabled ? 'active' : 'archived'} label={gateway.enabled ? t('Enabled') : t('Disabled')} /></p>
                        {gateway.fields.map((f) => (
                            <TextField key={f.key} label={f.label} name={`credentials[${f.key}]`} type={f.secret ? 'password' : 'text'} autoComplete="off" placeholder={f.secret && gateway.configured ? t('Saved — leave empty to keep') : ''} error={errors[`credentials.${f.key}`]} />
                        ))}
                        <SelectField label={t('Mode')} name="mode" defaultValue={gateway.mode} options={[{ value: 'sandbox', label: t('Sandbox') }, { value: 'live', label: t('Live') }]} />
                        <label className="flex items-center gap-2 text-sm"><input type="hidden" name="is_enabled" value="0" /><input type="checkbox" name="is_enabled" value="1" defaultChecked={gateway.enabled} className="size-4 accent-primary" />{t('Enabled')}</label>
                        <Button type="submit" size="sm" variant="outline" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                    </>
                )}
            </Form>
        </li>
    );
}

FeeSetup.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }] };
