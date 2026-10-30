import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * Translation hook. Keys are the English source strings (Laravel JSON style);
 * Bangla lives in lang/bn.json and is shared by HandleInertiaRequests.
 * Missing keys fall back to the English text.
 */
export function useT() {
    const { translations } = usePage().props;

    return useCallback(
        (key: string, replace: Record<string, string | number> = {}) => {
            let text = translations?.[key] ?? key;

            for (const [name, value] of Object.entries(replace)) {
                text = text.replaceAll(`:${name}`, String(value));
            }

            return text;
        },
        [translations],
    );
}

/** Format an amount in BDT using the active locale's digits. */
export function formatMoney(amount: number | string, locale = 'bn') {
    return new Intl.NumberFormat(locale === 'bn' ? 'bn-BD' : 'en-BD', {
        style: 'currency',
        currency: 'BDT',
    }).format(Number(amount));
}

/** Format an ISO timestamp in the school's timezone (default Asia/Dhaka). */
export function formatDateTime(
    iso: string | null | undefined,
    locale = 'bn',
    timeZone = 'Asia/Dhaka',
) {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(new Date(iso));
}
