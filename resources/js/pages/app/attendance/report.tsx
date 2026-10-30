import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/shared/page-header';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, report } from '@/routes/attendance';

type Row = { student_id: number; full_name: string; roll_no: string | null; present: number; absent: number; late: number; leave: number; half_day: number; marked: number; rate: number | null };

type Props = { section: { id: number; name: string; class: string }; month: string; workingDays: number; rows: Row[] };

export default function AttendanceReport({ section, month, workingDays, rows }: Props) {
    const t = useT();
    const cols: [keyof Row, string][] = [['present', t('present')], ['absent', t('absent')], ['late', t('late')], ['leave', t('leave')], ['half_day', t('half_day')]];

    return (
        <>
            <Head title={t('Attendance report')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={`${t('Attendance report')} · ${section.class} ${section.name}`}
                    description={t(':days working days so far this month. Rate = (present + late + ½ half day) ÷ days marked, leave excluded.', { days: workingDays })}
                    actions={<Input type="month" value={month} onChange={(e) => router.get(report.url(), { section: section.id, month: e.target.value })} className="w-44" aria-label={t('Month')} />}
                />
                <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-3">{t('Roll')}</th>
                                    <th className="px-4 py-3">{t('Name')}</th>
                                    {cols.map(([k, label]) => <th key={k} className="px-3 py-3 text-right">{label}</th>)}
                                    <th className="px-4 py-3 text-right">{t('Rate')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {rows.map((r) => (
                                    <tr key={r.student_id}>
                                        <td className="px-4 py-2 tabular-nums text-muted-foreground">{r.roll_no ?? '—'}</td>
                                        <td className="px-4 py-2 font-medium">{r.full_name}</td>
                                        {cols.map(([k]) => <td key={k} className="px-3 py-2 text-right tabular-nums">{r[k] as number}</td>)}
                                        <td className={cn('px-4 py-2 text-right font-medium tabular-nums', r.rate !== null && r.rate < 75 && 'text-destructive')}>{r.rate === null ? '—' : `${r.rate}%`}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

AttendanceReport.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Attendance', href: index() }] };
