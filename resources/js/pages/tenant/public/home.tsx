import { Head, Link, usePage } from '@inertiajs/react';
import { Mail, MapPin, Phone } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { login } from '@/routes';

type Props = {
    school: {
        name: string;
        name_bn: string | null;
        institution_type: string;
        address: string | null;
        phone: string | null;
        email: string | null;
    };
};

/** Public school website home (placeholder until the website builder in Milestone 6). */
export default function PublicHome({ school }: Props) {
    const t = useT();
    const { locale } = usePage().props;
    const title = locale === 'bn' && school.name_bn ? school.name_bn : school.name;

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <Head title={title}>
                <meta name="description" content={t(':name — official website', { name: title })} />
            </Head>
            <header className="border-b border-border/60">
                <div className="mx-auto flex h-16 max-w-5xl items-center justify-between px-4 sm:px-6">
                    <span className="font-semibold">{title}</span>
                    <Button asChild size="sm" variant="outline"><Link href={login()}>{t('Portal login')}</Link></Button>
                </div>
            </header>
            <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-16 sm:px-6">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
                {school.name_bn && locale !== 'bn' && <p className="mt-2 text-lg text-muted-foreground" lang="bn">{school.name_bn}</p>}
                <ul className="mt-8 grid gap-3 text-sm text-muted-foreground sm:grid-cols-3">
                    {school.address && <li className="flex gap-2"><MapPin className="size-4 shrink-0" />{school.address}</li>}
                    {school.phone && <li className="flex gap-2"><Phone className="size-4 shrink-0" />{school.phone}</li>}
                    {school.email && <li className="flex gap-2"><Mail className="size-4 shrink-0" />{school.email}</li>}
                </ul>
            </main>
        </div>
    );
}
