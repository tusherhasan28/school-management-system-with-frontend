import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    value: string | number;
    hint?: string;
    icon?: LucideIcon;
    tone?: 'default' | 'success' | 'warning' | 'danger';
};

const tones = {
    default: 'bg-primary/10 text-primary',
    success: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    danger: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
} as const;

export function StatCard({ label, value, hint, icon: Icon, tone = 'default' }: Props) {
    return (
        <div className="rounded-xl border border-border/70 bg-card p-5 shadow-xs">
            <div className="flex items-start justify-between gap-3">
                <div className="space-y-1">
                    <p className="text-sm font-medium text-muted-foreground">
                        {label}
                    </p>
                    <p className="text-2xl font-semibold tracking-tight tabular-nums">
                        {value}
                    </p>
                </div>
                {Icon && (
                    <span
                        className={cn(
                            'flex size-9 items-center justify-center rounded-lg',
                            tones[tone],
                        )}
                        aria-hidden
                    >
                        <Icon className="size-4.5" />
                    </span>
                )}
            </div>
            {hint && (
                <p className="mt-3 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
