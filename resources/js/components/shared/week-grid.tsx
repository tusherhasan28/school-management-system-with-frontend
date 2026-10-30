import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { weekdayName } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type Period = {
    id: number;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    subject: string;
    teacher?: string | null;
    class?: string;
    section?: string;
    room?: string | null;
    [key: string]: unknown;
};

// Bangladesh school weeks usually start on Sunday.
const order = [7, 1, 2, 3, 4, 5, 6];

/**
 * Weekly timetable: one column per school day (weekend columns hidden),
 * periods stacked by start time. Scrolls horizontally on phones.
 */
export function WeekGrid({ periods, weekend, render, onEmptyDay }: { periods: Period[]; weekend: number[]; render?: (p: Period) => ReactNode; onEmptyDay?: (day: number) => ReactNode }) {
    const { locale } = usePage().props;
    const days = order.filter((d) => !weekend.includes(d));

    return (
        <div className="overflow-x-auto rounded-xl border border-border/70 bg-card">
            <div className="grid min-w-[56rem]" style={{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }}>
                {days.map((day) => {
                    const items = periods.filter((p) => p.day_of_week === day).sort((a, b) => a.starts_at.localeCompare(b.starts_at));

                    return (
                        <div key={day} className="border-r border-border/70 last:border-r-0">
                            <div className="border-b border-border/70 bg-muted/50 px-3 py-2 text-sm font-medium">{weekdayName(day, locale, 'long')}</div>
                            <div className="grid gap-2 p-2">
                                {items.map((p) => (
                                    <div key={p.id} className={cn('rounded-lg border border-primary/20 bg-primary/5 p-2.5 text-sm')}>
                                        {render ? render(p) : (
                                            <>
                                                <p className="text-xs text-muted-foreground tabular-nums">{p.starts_at}–{p.ends_at}</p>
                                                <p className="font-medium">{p.subject}</p>
                                                <p className="text-xs text-muted-foreground">{[p.class && `${p.class} ${p.section ?? ''}`, p.teacher, p.room].filter(Boolean).join(' · ')}</p>
                                            </>
                                        )}
                                    </div>
                                ))}
                                {onEmptyDay?.(day)}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
