import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { formatDate, useT } from '@/lib/i18n';
import { show } from '@/routes/subscription';

/** Shown to people who can see the subscription when action is needed soon. */
export function SubscriptionBanner() {
    const t = useT();
    const { subscriptionNotice } = usePage<{ subscriptionNotice?: { status: string; date: string | null } | null }>().props;

    if (!subscriptionNotice) {
        return null;
    }

    const text = {
        past_due: t('Payment is overdue. Pay by :date to keep the school open.', { date: formatDate(subscriptionNotice.date) }),
        trial_ending: t('Your trial ends on :date.', { date: formatDate(subscriptionNotice.date) }),
        cancelling: t('Your subscription ends on :date.', { date: formatDate(subscriptionNotice.date) }),
    }[subscriptionNotice.status];

    return (
        <div role="status" className="flex flex-wrap items-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm md:px-6">
            <AlertTriangle className="size-4 text-amber-700" />
            <span>{text}</span>
            <Link href={show()} className="font-medium text-primary hover:underline">{t('View subscription')}</Link>
        </div>
    );
}
