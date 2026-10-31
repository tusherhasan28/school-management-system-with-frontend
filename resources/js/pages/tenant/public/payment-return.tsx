import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock, XCircle } from 'lucide-react';
import { useT } from '@/lib/i18n';

type Props = {
    school: { name: string; name_bn: string | null };
    payment: { status: string; amount: string; receipt_no: string | null } | null;
};

/**
 * Where the payer lands after the gateway. It only displays the status the
 * server already knows; it never confirms a payment by itself.
 */
export default function PaymentReturn({ school, payment }: Props) {
    const t = useT();
    const status = payment?.status ?? 'unknown';
    const Icon = status === 'successful' ? CheckCircle2 : status === 'pending' ? Clock : XCircle;

    return (
        <>
            <Head title={t('Payment status')} />
            <main className="flex min-h-screen items-center justify-center bg-muted/40 p-6">
                <section className="w-full max-w-md rounded-2xl border border-border/70 bg-card p-8 text-center shadow-sm">
                    <p className="text-sm text-muted-foreground">{school.name}</p>
                    <Icon className={status === 'successful' ? 'mx-auto mt-4 size-12 text-emerald-600' : status === 'pending' ? 'mx-auto mt-4 size-12 text-amber-600' : 'mx-auto mt-4 size-12 text-rose-600'} />
                    <h1 className="mt-3 text-xl font-semibold">{t('payment_return_' + status)}</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {status === 'pending' ? t('We are confirming the payment with the provider. This page does not need to stay open; the school will see the payment once it is confirmed.') : null}
                        {status === 'successful' && payment ? t('Receipt :receipt for ৳ :amount.', { receipt: payment.receipt_no ?? '', amount: payment.amount }) : null}
                        {status !== 'pending' && status !== 'successful' ? t('If money was taken from your account, contact the school office with the transaction ID.') : null}
                    </p>
                </section>
            </main>
        </>
    );
}
