import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, School as SchoolIcon, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatusBadge } from '@/components/shared/status-badge';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes/platform';
import { index, show } from '@/routes/platform/schools';
import type { Paginated } from '@/types';

export type PlatformSchool = {
    id: number;
    name: string;
    name_bn: string | null;
    slug: string;
    institution_type: string;
    status: string;
    status_label: string;
    url: string;
    created_at: string | null;
};

type Props = {
    schools: Paginated<PlatformSchool>;
    filters: { search: string; status: string; sort: string; direction: string };
    statuses: { value: string; label: string }[];
};

export default function SchoolsIndex({ schools, filters, statuses }: Props) {
    const t = useT();
    const [search, setSearch] = useState(filters.search);

    const apply = (next: Partial<Props['filters']>) => {
        const query = { ...filters, ...next };
        router.get(
            index.url(),
            Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '')),
            { preserveState: true, replace: true },
        );
    };

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const id = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <>
            <Head title={t('Schools')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Schools')}
                    description={t('Every institution on the platform. Onboarding UI arrives in Milestone 2; use `php artisan school:create` for now.')}
                />
                <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="relative flex-1 sm:max-w-sm">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('Search name, slug or email')}
                            className="pl-9"
                            aria-label={t('Search name, slug or email')}
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => apply({ status: v === 'all' ? '' : v })}>
                        <SelectTrigger className="sm:w-48" aria-label={t('Status')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{t('All statuses')}</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {schools.data.length === 0 ? (
                    <EmptyState icon={SchoolIcon} title={t('No schools found')} />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">{t('School')}</th>
                                        <th scope="col" className="px-4 py-3">{t('Status')}</th>
                                        <th scope="col" className="hidden px-4 py-3 md:table-cell">{t('Type')}</th>
                                        <th scope="col" className="hidden px-4 py-3 lg:table-cell">{t('Created')}</th>
                                        <th scope="col" className="px-4 py-3"><span className="sr-only">{t('Open')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {schools.data.map((school) => (
                                        <tr key={school.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <Link href={show(school.id)} className="font-medium hover:underline">
                                                    {school.name}
                                                </Link>
                                                <div className="font-mono text-xs text-muted-foreground">{school.slug}</div>
                                            </td>
                                            <td className="px-4 py-3"><StatusBadge status={school.status} label={school.status_label} /></td>
                                            <td className="hidden px-4 py-3 capitalize md:table-cell">{school.institution_type}</td>
                                            <td className="hidden px-4 py-3 text-muted-foreground lg:table-cell">{formatDateTime(school.created_at, 'en')}</td>
                                            <td className="px-4 py-3 text-right">
                                                <a href={school.url} target="_blank" rel="noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" aria-label={t('Open :name website', { name: school.name })}>
                                                    <ExternalLink className="size-4" />
                                                </a>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
                <Pagination page={schools} />
            </div>
        </>
    );
}

SchoolsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Schools', href: index() },
    ],
};
