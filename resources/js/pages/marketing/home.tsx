import { Head, Link } from '@inertiajs/react';
import { BookOpenCheck, CalendarCheck, Globe, Languages, ShieldCheck, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

export default function MarketingHome() {
    const t = useT();

    const highlights = [
        { icon: CalendarCheck, title: t('Attendance & timetable'), body: t('Daily or period-wise attendance with parent alerts.') },
        { icon: BookOpenCheck, title: t('Exams & results'), body: t('Configurable grading and printable bilingual report cards.') },
        { icon: Wallet, title: t('Fees in BDT'), body: t('Invoices, partial payments and receipts, ready for bKash, Nagad and SSLCommerz.') },
        { icon: Globe, title: t('School website'), body: t('Your own subdomain with notices, admissions and gallery.') },
        { icon: Languages, title: t('Bangla & English'), body: t('Every portal works in both languages.') },
        { icon: ShieldCheck, title: t('Private by design'), body: t('Each school’s data is isolated and audited.') },
    ];

    return (
        <>
            <Head title={t('School management for Bangladesh')} />
            <section className="mx-auto max-w-6xl px-4 pt-16 pb-12 sm:px-6 sm:pt-24">
                <div className="max-w-3xl space-y-6">
                    <p className="inline-flex rounded-full border px-3 py-1 text-xs font-medium text-muted-foreground">
                        {t('Schools · Colleges · Madrasas · Kindergartens')}
                    </p>
                    <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                        {t('Run your whole institution from one place')}
                    </h1>
                    <p className="text-lg text-pretty text-muted-foreground">
                        {t('Admissions, attendance, exams, fees, parent portal and a public website — built for how institutions in Bangladesh work.')}
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button asChild size="lg"><Link href="/register-school">{t('Start a free trial')}</Link></Button>
                        <Button asChild size="lg" variant="outline"><Link href="/features">{t('See features')}</Link></Button>
                    </div>
                </div>
            </section>
            <section className="mx-auto grid max-w-6xl gap-4 px-4 pb-24 sm:grid-cols-2 sm:px-6 lg:grid-cols-3">
                {highlights.map(({ icon: Icon, title, body }) => (
                    <div key={title} className="rounded-xl border border-border/70 bg-card p-5">
                        <Icon className="mb-3 size-5 text-primary" aria-hidden />
                        <h2 className="font-medium">{title}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">{body}</p>
                    </div>
                ))}
            </section>
        </>
    );
}
