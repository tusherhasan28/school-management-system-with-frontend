import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/campuses';
import type { Campus } from './index';

export default function CampusShow({ campus }: { campus: Campus }) {
    const t = useT();
    const rows: [string, string | null][] = [
        [t('Name (Bangla)'), campus.name_bn],
        [t('Code'), campus.code],
        [t('Phone'), campus.phone],
        [t('Address'), campus.address],
    ];

    return (
        <>
            <Head title={campus.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={campus.name}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={index()}>
                                <ArrowLeft />
                                {t('Back to campuses')}
                            </Link>
                        </Button>
                    }
                />
                <dl className="grid max-w-2xl gap-x-6 gap-y-4 rounded-xl border border-border/70 bg-card p-5 sm:grid-cols-2">
                    {rows.map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-sm text-muted-foreground">{label}</dt>
                            <dd className="mt-1">{value || '—'}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </>
    );
}
