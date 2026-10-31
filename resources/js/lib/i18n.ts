import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * Translation hook. Keys are the English source strings (Laravel JSON style);
 * Bangla lives in lang/bn.json and is shared by HandleInertiaRequests.
 * Missing keys fall back to the English text.
 */
/**
 * Formatting defaults for the page being shown. useT() runs on every page,
 * so it keeps these in step with the active locale and school timezone;
 * callers of formatDateTime/formatDate no longer need to pass them.
 */
const current = { locale: 'bn', timeZone: 'Asia/Dhaka' };

export function useT() {
    const { translations, locale, school } = usePage().props;
    current.locale = locale ?? current.locale;
    current.timeZone = school?.timezone ?? 'Asia/Dhaka';

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
export function formatMoney(amount: number | string, locale = current.locale) {
    return new Intl.NumberFormat(locale === 'bn' ? 'bn-BD' : 'en-BD', {
        style: 'currency',
        currency: 'BDT',
    }).format(Number(amount));
}

/** Format an ISO timestamp in the school's timezone (default Asia/Dhaka). */
export function formatDateTime(
    iso: string | null | undefined,
    locale = current.locale,
    timeZone = current.timeZone,
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

/** Format a plain date (YYYY-MM-DD) without shifting it across timezones. */
export function formatDate(date: string | null | undefined, locale = current.locale) {
    if (!date) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(`${date.slice(0, 10)}T00:00:00Z`));
}

/** The calendar day of a timestamp in the school's timezone (for period ends, due dates, …). */
export function formatDay(iso: string | null | undefined, locale = current.locale, timeZone = current.timeZone) {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { dateStyle: 'medium', timeZone }).format(new Date(iso));
}

/** Short weekday name for an ISO day number (1 = Monday … 7 = Sunday). */
export function weekdayName(isoDay: number, locale = current.locale, style: 'short' | 'long' = 'short') {
    // 2024-01-01 was a Monday.
    const date = new Date(Date.UTC(2024, 0, isoDay));

    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { weekday: style, timeZone: 'UTC' }).format(date);
}
