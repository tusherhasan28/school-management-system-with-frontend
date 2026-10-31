import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { CatalogueItem, Plan } from '@/components/billing/shared';
import { limitLabel } from '@/components/billing/shared';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, useT } from '@/lib/i18n';
import { dashboard } from '@/routes/platform';
import { index, store, update } from '@/routes/platform/plans';

type Props = { plans: Plan[]; catalogue: CatalogueItem[]; can: { manage: boolean } };

export default function PlansIndex({ plans, catalogue, can }: Props) {
    const t = useT();
    const [editing, setEditing] = useState<Plan | 'new' | null>(null);

    return (
        <>
            <Head title={t('Plans')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Plans')}
                    description={t('Prices, trial and grace days, limits and features. Changes apply to every school on the plan at once.')}
                    actions={can.manage && <Button onClick={() => setEditing('new')}><Plus />{t('New plan')}</Button>}
                />
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {plans.map((p) => (
                        <section key={p.id} className="flex flex-col rounded-xl border border-border/70 bg-card p-5">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <h2 className="font-semibold">{p.name}</h2>
                                    <p className="font-mono text-xs text-muted-foreground">{p.code}</p>
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    <StatusBadge status={p.is_active ? 'active' : 'archived'} label={p.is_active ? t('Active') : t('Inactive')} />
                                    {!p.is_public && <StatusBadge status="pending" label={t('Hidden')} />}
                                </div>
                            </div>
                            <p className="mt-3 text-2xl font-semibold tabular-nums">{formatMoney(p.price_monthly, 'en')}<span className="text-sm font-normal text-muted-foreground"> / {t('month')}</span></p>
                            <p className="text-sm text-muted-foreground">{formatMoney(p.price_yearly, 'en')} / {t('year')} · {t(':days-day trial', { days: String(p.trial_days) })} · {t(':days days grace', { days: String(p.grace_days) })}</p>
                            <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                                {catalogue.map((c) => (
                                    <div key={c.key} className="contents">
                                        <dt className="text-muted-foreground">{t(`feature_${c.key}`)}</dt>
                                        <dd className="text-right tabular-nums">{limitLabel(p.features?.[c.key], t)}</dd>
                                    </div>
                                ))}
                            </dl>
                            <div className="mt-auto flex items-center justify-between pt-4 text-sm text-muted-foreground">
                                <span>{t(':count schools', { count: String(p.subscriptions ?? 0) })}</span>
                                {can.manage && <Button size="sm" variant="outline" onClick={() => setEditing(p)}><Pencil />{t('Edit')}</Button>}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
            {editing && <PlanDialog plan={editing === 'new' ? null : editing} catalogue={catalogue} onClose={() => setEditing(null)} />}
        </>
    );
}

function PlanDialog({ plan, catalogue, onClose }: { plan: Plan | null; catalogue: CatalogueItem[]; onClose: () => void }) {
    const t = useT();
    const features = Object.fromEntries(catalogue.map((c) => [c.key, plan?.features?.[c.key] ?? (c.type === 'switch' ? false : null)]));
    const form = useForm({
        code: plan?.code ?? '', name: plan?.name ?? '', name_bn: plan?.name_bn ?? '', description: plan?.description ?? '',
        price_monthly: plan?.price_monthly ?? '0', price_yearly: plan?.price_yearly ?? '0', trial_days: plan?.trial_days ?? 14, grace_days: plan?.grace_days ?? 7,
        is_active: plan?.is_active ?? true, is_public: plan?.is_public ?? true, sort_order: plan?.sort_order ?? 0,
        features: features as Record<string, number | boolean | null>,
    });
    const errors = form.errors as Record<string, string>;
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (plan) {
            form.put(update.url(plan.id), opts);
        } else {
            form.post(store.url(), opts);
        }
    };
    const setFeature = (key: string, value: number | boolean | null) => form.setData('features', { ...form.data.features, [key]: value });

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>{plan ? t('Edit :name', { name: plan.name }) : t('New plan')}</DialogTitle>
                <DialogDescription>{t('Leave a limit empty for unlimited. Unticked features are not available on the plan.')}</DialogDescription>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {([['code', t('Code')], ['name', t('Name')], ['name_bn', t('Name (Bangla)')], ['sort_order', t('Order')], ['price_monthly', t('Monthly price (BDT)')], ['price_yearly', t('Yearly price (BDT)')], ['trial_days', t('Trial days')], ['grace_days', t('Grace days')]] as const).map(([key, label]) => (
                            <div key={key} className="grid gap-1.5">
                                <Label htmlFor={`plan-${key}`}>{label}</Label>
                                <Input id={`plan-${key}`} value={String(form.data[key] ?? '')} onChange={(e) => form.setData(key, e.target.value as never)} inputMode={key.startsWith('price') || key.endsWith('days') || key === 'sort_order' ? 'decimal' : undefined} lang={key === 'name_bn' ? 'bn' : undefined} />
                                <InputError message={errors[key]} />
                            </div>
                        ))}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-description">{t('Description')}</Label>
                        <textarea id="plan-description" className="min-h-16 rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </div>
                    <fieldset className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
                        <legend className="px-1 text-sm font-medium">{t('Limits and features')}</legend>
                        {catalogue.map((c) => c.type === 'limit' ? (
                            <div key={c.key} className="grid gap-1.5">
                                <Label htmlFor={`f-${c.key}`}>{t(`feature_${c.key}`)}</Label>
                                <Input id={`f-${c.key}`} inputMode="numeric" placeholder={t('Unlimited')} value={form.data.features[c.key] === null ? '' : String(form.data.features[c.key])}
                                    onChange={(e) => setFeature(c.key, e.target.value === '' ? null : Number(e.target.value.replace(/\D/g, '')))} />
                                <InputError message={errors[`features.${c.key}`]} />
                            </div>
                        ) : (
                            <label key={c.key} className="flex items-center gap-2 text-sm">
                                <Checkbox checked={!!form.data.features[c.key]} onCheckedChange={(v) => setFeature(c.key, v === true)} />{t(`feature_${c.key}`)}
                            </label>
                        ))}
                    </fieldset>
                    <div className="flex flex-wrap gap-4 text-sm">
                        <label className="flex items-center gap-2"><Checkbox checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v === true)} />{t('Can be assigned')}</label>
                        <label className="flex items-center gap-2"><Checkbox checked={form.data.is_public} onCheckedChange={(v) => form.setData('is_public', v === true)} />{t('Shown on the pricing page')}</label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={onClose}>{t('Cancel')}</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing && <Spinner />}{t('Save plan')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

PlansIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Plans', href: index() }] };
