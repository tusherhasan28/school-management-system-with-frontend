import { Head } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import type { Period } from '@/components/shared/week-grid';
import { WeekGrid } from '@/components/shared/week-grid';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { mine } from '@/routes/timetable';

export default function MyTimetable({ periods, weekend, teacher }: { periods: Period[]; weekend: number[]; teacher: string }) {
    const t = useT();

    return (
        <>
            <Head title={t('My timetable')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('My timetable')} description={teacher} />
                {periods.length === 0 ? <EmptyState icon={CalendarRange} title={t('No periods assigned to you yet')} /> : <WeekGrid periods={periods} weekend={weekend} />}
            </div>
        </>
    );
}

MyTimetable.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'My timetable', href: mine() }] };
