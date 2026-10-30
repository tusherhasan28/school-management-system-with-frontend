import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Column<T> = {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    className?: string;
    /** Hide below a breakpoint to keep tables readable on phones. */
    hideBelow?: 'sm' | 'md' | 'lg';
};

const hide = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' } as const;

/**
 * Presentational table for server-paginated data. Filtering, sorting and
 * pagination happen on the server; pair with <Pagination>.
 */
export function DataTable<T>({ columns, rows, rowKey, onRowClick }: { columns: Column<T>[]; rows: T[]; rowKey: (row: T) => string | number; onRowClick?: (row: T) => void }) {
    return (
        <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <tr>
                            {columns.map((c) => (
                                <th key={c.key} scope="col" className={cn('px-4 py-3 whitespace-nowrap', c.hideBelow && hide[c.hideBelow], c.className)}>
                                    {c.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border/70">
                        {rows.map((row) => (
                            <tr key={rowKey(row)} className={cn('hover:bg-muted/30', onRowClick && 'cursor-pointer')} onClick={onRowClick ? () => onRowClick(row) : undefined}>
                                {columns.map((c) => (
                                    <td key={c.key} className={cn('px-4 py-3 align-top', c.hideBelow && hide[c.hideBelow], c.className)}>
                                        {c.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
