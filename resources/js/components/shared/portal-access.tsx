import { router, usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type Props = {
    /** invited | active | disabled | locked, or null when there is no portal login. */
    status: string | null;
    inviteUrl: string;
    disableUrl: string;
    kind: 'parent' | 'student';
    hasEmail: boolean;
};

/** Staff card: give a guardian or student a portal login, re-send the invitation, or turn access off. */
export function PortalAccess({ status, inviteUrl, disableUrl, kind, hasEmail }: Props) {
    const t = useT();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [busy, setBusy] = useState(false);
    const send = () => router.post(inviteUrl, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const tone = status === 'active' ? 'active' : status === 'invited' ? 'pending' : status ? 'suspended' : 'archived';

    return (
        <section className="rounded-xl border border-border/70 bg-card p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 font-medium"><KeyRound className="size-4 text-muted-foreground" />{kind === 'parent' ? t('Parent portal') : t('Student portal')}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {kind === 'parent' ? t('Lets this guardian see only their own linked children.') : t('Lets this student see only their own records.')}
                    </p>
                </div>
                <StatusBadge status={tone} label={status ? t(`portal_status_${status}`) : t('No login')} />
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
                {status === null && <Button size="sm" disabled={busy || !hasEmail} onClick={send}>{t('Send invitation')}</Button>}
                {status === 'invited' && <Button size="sm" variant="outline" disabled={busy} onClick={send}>{t('Re-send invitation')}</Button>}
                {status === 'disabled' && <Button size="sm" variant="outline" disabled={busy} onClick={send}>{t('Turn access back on')}</Button>}
                {(status === 'invited' || status === 'active') && (
                    <ConfirmDialog
                        trigger={<Button size="sm" variant="ghost">{t('Turn access off')}</Button>}
                        title={t('Turn portal access off?')}
                        description={t('They will no longer be able to sign in to the portal. You can turn it back on later.')}
                        confirmLabel={t('Turn access off')}
                        destructive
                        processing={busy}
                        onConfirm={() => router.delete(disableUrl, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                    />
                )}
            </div>
            {status === null && !hasEmail && <p className="mt-2 text-xs text-muted-foreground">{t('Add an email address first: portal invitations are sent by email.')}</p>}
            {errors.email && <p className="mt-2 text-sm text-destructive">{errors.email}</p>}
        </section>
    );
}
