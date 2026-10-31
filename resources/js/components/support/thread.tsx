import { StatusBadge } from '@/components/shared/status-badge';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type TicketMessage = { id: number; from: 'school' | 'platform'; name: string; message: string; created_at: string | null; internal?: boolean };

const ticketTone: Record<string, string> = { open: 'pending', in_progress: 'trial', waiting: 'pending', resolved: 'active', closed: 'archived' };

export function TicketStatus({ status }: { status: string }) {
    const t = useT();

    return <StatusBadge status={ticketTone[status] ?? 'archived'} label={t(`ticket_${status}`)} />;
}

/** Conversation view; `mine` is the side the reader is on. */
export function Thread({ messages, mine, locale }: { messages: TicketMessage[]; mine: 'school' | 'platform'; locale?: string }) {
    const t = useT();

    return (
        <ol className="space-y-3">
            {messages.map((m) => (
                <li key={m.id} className={cn('flex', m.from === mine ? 'justify-end' : 'justify-start')}>
                    <div className={cn('max-w-[85%] rounded-xl border px-4 py-3 text-sm', m.internal ? 'border-amber-500/40 bg-amber-500/10' : m.from === mine ? 'border-primary/30 bg-primary/5' : 'bg-card')}>
                        <p className="mb-1 text-xs text-muted-foreground">
                            {m.name} · {formatDateTime(m.created_at, locale)}{m.internal ? ` · ${t('Internal note')}` : ''}
                        </p>
                        <p className="whitespace-pre-line">{m.message}</p>
                    </div>
                </li>
            ))}
        </ol>
    );
}
