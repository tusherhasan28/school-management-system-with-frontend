import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

/** Shared shell for short marketing pages that are filled in later milestones. */
export function SimplePage({ title, intro, children }: { title: string; intro: string; children?: ReactNode }) {
    return (
        <>
            <Head title={title} />
            <section className="mx-auto max-w-3xl space-y-4 px-4 py-16 sm:px-6">
                <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
                <p className="text-muted-foreground">{intro}</p>
                {children}
            </section>
        </>
    );
}
