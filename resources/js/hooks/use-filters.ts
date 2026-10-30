import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Filters = Record<string, string | number | null | undefined>;

/**
 * Server-side filters kept in the query string. Text search is debounced;
 * other filters apply immediately.
 */
export function useFilters<T extends Filters>(url: string, initial: T) {
    const [filters, setFilters] = useState<T>(initial);
    const first = useRef(true);

    const apply = (next: T) => {
        const query = Object.fromEntries(
            Object.entries(next).filter(([, v]) => v !== '' && v !== null && v !== undefined),
        );
        router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const id = setTimeout(() => apply(filters), 300);

        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters]);

    const set = <K extends keyof T>(key: K, value: T[K]) => setFilters((f) => ({ ...f, [key]: value }));

    return { filters, set, setFilters };
}
