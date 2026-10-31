import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index, read } from '@/routes/notifications';

type Item = { id: string; title: string; body: string | null; url: string | null; read: boolean; created_at: string | null };

function csrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Notification centre: unread count from shared props, list fetched when opened. */
export function NotificationBell({ className }: { className?: string }) {
    const t = useT();
    const { unreadNotifications, auth } = usePage<{ unreadNotifications?: number }>().props;
    const [items, setItems] = useState<Item[] | null>(null);
    const unread = unreadNotifications ?? 0;

    const load = async () => {
        const res = await fetch(index.url(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

        if (res.ok) {
            setItems((await res.json()).items);
        }
    };

    const markAll = async () => {
        await fetch(read.url(), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() }, credentials: 'same-origin', body: '{}' });
        setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
        router.reload({ only: ['unreadNotifications'] });
    };

    // School users only (the platform area has its own guard and no bell yet).
    if (!auth?.user) {
        return null;
    }

    return (
        <DropdownMenu onOpenChange={(open) => open && load()}>
            <DropdownMenuTrigger className={cn('relative inline-flex size-9 items-center justify-center rounded-md hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none', className)} aria-label={t('Notifications')}>
                <Bell className="size-5" />
                {unread > 0 && <span className="absolute top-1 right-1 min-w-4 rounded-full bg-rose-600 px-1 text-[10px] leading-4 font-semibold text-white tabular-nums">{unread > 9 ? '9+' : unread}</span>}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-0">
                <div className="flex items-center justify-between border-b px-3 py-2">
                    <p className="text-sm font-medium">{t('Notifications')}</p>
                    {unread > 0 && <button type="button" className="text-xs text-primary hover:underline" onClick={markAll}>{t('Mark all read')}</button>}
                </div>
                <div className="max-h-96 overflow-y-auto">
                    {items === null ? <p className="px-3 py-6 text-center text-sm text-muted-foreground">…</p> : items.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">{t('No notifications')}</p>
                    ) : (
                        <ul className="divide-y">
                            {items.map((i) => (
                                <li key={i.id}>
                                    <button type="button" className={cn('w-full px-3 py-2 text-left text-sm hover:bg-muted', !i.read && 'bg-primary/5')} onClick={() => i.url && router.visit(i.url)}>
                                        <p className={cn(!i.read && 'font-medium')}>{i.title}</p>
                                        {i.body && <p className="text-muted-foreground">{i.body}</p>}
                                        <p className="text-xs text-muted-foreground">{formatDateTime(i.created_at)}</p>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
