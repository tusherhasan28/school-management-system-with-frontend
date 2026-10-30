import { Form, Head, router } from '@inertiajs/react';
import { Building2, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import CampusController from '@/actions/App/Modules/Setup/Http/Controllers/CampusController';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/campuses';
import type { Paginated } from '@/types';

export type Campus = {
    id: number;
    name: string;
    name_bn: string | null;
    code: string | null;
    address: string | null;
    phone: string | null;
    is_main: boolean;
};

type Props = {
    campuses: Paginated<Campus>;
    filters: { search: string };
    can: { manage: boolean };
};

export default function CampusesIndex({ campuses, filters, can }: Props) {
    const t = useT();
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<Campus | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<number | null>(null);

    // Debounced server-side search.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const id = setTimeout(() => {
            router.get(
                index.url(),
                search ? { search } : {},
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(id);
    }, [search, filters.search]);

    const destroy = (campus: Campus) => {
        setDeleting(campus.id);
        router.delete(CampusController.destroy.url(campus.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    };

    return (
        <>
            <Head title={t('Campuses')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Campuses')}
                    description={t('Physical locations of your institution.')}
                    actions={
                        can.manage && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus />
                                {t('Add campus')}
                            </Button>
                        )
                    }
                />

                <div className="relative max-w-sm">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('Search by name or code')}
                        className="pl-9"
                        aria-label={t('Search by name or code')}
                    />
                </div>

                {campuses.data.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title={filters.search ? t('No campuses match your search') : t('No campuses yet')}
                        description={t('Add a campus to organise classes and staff by location.')}
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">{t('Name')}</th>
                                        <th scope="col" className="px-4 py-3">{t('Code')}</th>
                                        <th scope="col" className="hidden px-4 py-3 md:table-cell">{t('Phone')}</th>
                                        <th scope="col" className="hidden px-4 py-3 lg:table-cell">{t('Address')}</th>
                                        {can.manage && (
                                            <th scope="col" className="px-4 py-3 text-right">
                                                <span className="sr-only">{t('Actions')}</span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {campuses.data.map((campus) => (
                                        <tr key={campus.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2 font-medium">
                                                    {campus.name}
                                                    {campus.is_main && (
                                                        <Badge variant="secondary">{t('Main')}</Badge>
                                                    )}
                                                </div>
                                                {campus.name_bn && (
                                                    <div className="text-muted-foreground">{campus.name_bn}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs">{campus.code ?? '—'}</td>
                                            <td className="hidden px-4 py-3 md:table-cell">{campus.phone ?? '—'}</td>
                                            <td className="hidden max-w-xs truncate px-4 py-3 text-muted-foreground lg:table-cell">
                                                {campus.address ?? '—'}
                                            </td>
                                            {can.manage && (
                                                <td className="px-4 py-3">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            onClick={() => setEditing(campus)}
                                                            aria-label={t('Edit :name', { name: campus.name })}
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        {!campus.is_main && (
                                                            <ConfirmDialog
                                                                trigger={
                                                                    <Button
                                                                        size="icon"
                                                                        variant="ghost"
                                                                        aria-label={t('Delete :name', { name: campus.name })}
                                                                    >
                                                                        <Trash2 className="text-destructive" />
                                                                    </Button>
                                                                }
                                                                title={t('Delete campus?')}
                                                                description={t('This cannot be undone.')}
                                                                confirmLabel={t('Delete')}
                                                                destructive
                                                                processing={deleting === campus.id}
                                                                onConfirm={() => destroy(campus)}
                                                            />
                                                        )}
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <Pagination page={campuses} />
            </div>

            <CampusFormDialog
                open={creating || editing !== null}
                campus={editing}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
            />
        </>
    );
}

function CampusFormDialog({
    open,
    campus,
    onClose,
}: {
    open: boolean;
    campus: Campus | null;
    onClose: () => void;
}) {
    const t = useT();
    const action = campus
        ? CampusController.update.form(campus.id)
        : CampusController.store.form();

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{campus ? t('Edit campus') : t('Add campus')}</DialogTitle>
                <DialogDescription>
                    {t('Bangla name is optional and shown where the interface is in Bangla.')}
                </DialogDescription>
                <Form
                    key={campus?.id ?? 'new'}
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">{t('Name')}</Label>
                                    <Input id="name" name="name" defaultValue={campus?.name} required />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="name_bn">{t('Name (Bangla)')}</Label>
                                    <Input id="name_bn" name="name_bn" lang="bn" defaultValue={campus?.name_bn ?? ''} />
                                    <InputError message={errors.name_bn} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="code">{t('Code')}</Label>
                                    <Input id="code" name="code" defaultValue={campus?.code ?? ''} />
                                    <InputError message={errors.code} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">{t('Phone')}</Label>
                                    <Input id="phone" name="phone" type="tel" defaultValue={campus?.phone ?? ''} />
                                    <InputError message={errors.phone} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="address">{t('Address')}</Label>
                                <Input id="address" name="address" defaultValue={campus?.address ?? ''} />
                                <InputError message={errors.address} />
                            </div>
                            <div className="flex items-center gap-3">
                                <input type="hidden" name="is_main" value="0" />
                                <Checkbox id="is_main" name="is_main" value="1" defaultChecked={campus?.is_main} />
                                <Label htmlFor="is_main">{t('Main campus')}</Label>
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="secondary" onClick={onClose}>
                                    {t('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('Save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

CampusesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Campuses', href: index() },
    ],
};
