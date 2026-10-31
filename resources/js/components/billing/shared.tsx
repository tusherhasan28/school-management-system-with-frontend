import { StatusBadge } from '@/components/shared/status-badge';
import { useT } from '@/lib/i18n';

export type PlanFeatureValues = Record<string, number | boolean | null>;

export type Plan = {
    id: number; code: string; name: string; name_bn: string | null; description: string | null;
    price_monthly: string; price_yearly: string; trial_days: number; grace_days: number;
    is_active: boolean; is_public: boolean; sort_order: number; features: PlanFeatureValues | null; subscriptions?: number;
};

export type CatalogueItem = { key: string; type: 'limit' | 'switch'; unit: string | null };

export type Subscription = {
    id: number; status: string; billing_cycle: 'monthly' | 'yearly'; amount: string;
    plan: { id: number; code: string; name: string; name_bn: string | null } | null;
    starts_at: string; current_period_start: string; current_period_end: string; trial_ends_at: string | null; grace_ends_at: string | null;
    auto_renew: boolean; cancel_at_period_end: boolean; suspended_at: string | null; ended_at: string | null;
};

export type PlatformInvoice = {
    id: number; invoice_number: string; description: string; school_id: number; school: string | null; total: string; currency: string;
    status: 'unpaid' | 'paid' | 'void'; overdue: boolean; issued_at: string; due_at: string; paid_at: string | null;
    period_start: string | null; period_end: string | null; void_reason: string | null;
};

const subscriptionTone: Record<string, string> = { trialing: 'trial', active: 'active', past_due: 'pending', suspended: 'suspended', cancelled: 'cancelled', expired: 'archived' };

export function SubscriptionBadge({ status }: { status: string }) {
    const t = useT();

    return <StatusBadge status={subscriptionTone[status] ?? 'archived'} label={t(`subscription_${status}`)} />;
}

export function InvoiceBadge({ invoice }: { invoice: Pick<PlatformInvoice, 'status' | 'overdue'> }) {
    const t = useT();
    const key = invoice.overdue ? 'overdue' : invoice.status;
    const tone = { unpaid: 'pending', overdue: 'suspended', paid: 'active', void: 'archived' }[key] ?? 'archived';

    return <StatusBadge status={tone} label={t(`pinvoice_${key}`)} />;
}

/** Limit value for display: null = unlimited. */
export function limitLabel(value: number | boolean | null | undefined, t: (k: string) => string) {
    if (typeof value === 'boolean') {
        return value ? t('Included') : t('Not included');
    }

    return value === null || value === undefined ? t('Unlimited') : String(value);
}
