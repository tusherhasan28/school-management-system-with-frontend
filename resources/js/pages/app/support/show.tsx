import { Head, router, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import type { TicketMessage } from '@/components/support/thread';
import { Thread, TicketStatus } from '@/components/support/thread';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { close, index, reply } from '@/routes/support';

type Props = {
    ticket: { id: number; reference: string; subject: string; category: string; priority: string; status: string; created_at: string | null };
    messages: TicketMessage[];
};

export default function SupportShow({ ticket, messages }: Props) {
    const t = useT();
    const form = useForm({ message: '' });
    const closed = ticket.status === 'closed';

    return (
        <>
            <Head title={ticket.reference} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={ticket.subject} description={`${ticket.reference} · ${t(`ticket_category_${ticket.category}`)} · ${t(`priority_${ticket.priority}`)}`}
                    actions={<><TicketStatus status={ticket.status} />{!closed && <Button variant="outline" onClick={() => router.post(close.url(ticket.id))}>{t('Close ticket')}</Button>}</>} />
                <div className="max-w-3xl"><Thread messages={messages} mine="school" /></div>
                {!closed && (
                    <form className="grid max-w-3xl gap-2" onSubmit={(e) => { e.preventDefault(); form.post(reply.url(ticket.id), { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                        <label htmlFor="reply" className="text-sm font-medium">{t('Reply')}</label>
                        <textarea id="reply" className="min-h-24 rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} />
                        <InputError message={form.errors.message} />
                        <div><Button type="submit" disabled={form.processing}>{form.processing && <Spinner />}{t('Send')}</Button></div>
                    </form>
                )}
            </div>
        </>
    );
}

SupportShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Support', href: index() }] };
