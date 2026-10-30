import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

// Plain paths: marketing routes are bound to the root domain, so generated
// helpers would be absolute (and lose the port in local development).
const paths = { home: '/', features: '/features', pricing: '/pricing', login: '/login', registerSchool: '/register-school' };

export default function MarketingLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const { name } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            <header className="sticky top-0 z-20 border-b border-border/60 bg-background/85 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                    <Link href={paths.home} className="flex items-center gap-2 font-semibold">
                        <span className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">শ</span>
                        {name}
                    </Link>
                    <nav className="hidden items-center gap-6 text-sm text-muted-foreground md:flex" aria-label={t('Main')}>
                        <Link href={paths.features} className="hover:text-foreground">{t('Features')}</Link>
                        <Link href={paths.pricing} className="hover:text-foreground">{t('Pricing')}</Link>
                        <Link href={paths.login} className="hover:text-foreground">{t('Log in')}</Link>
                    </nav>
                    <Button asChild size="sm">
                        <Link href={paths.registerSchool}>{t('Register your school')}</Link>
                    </Button>
                </div>
            </header>
            <main className="flex-1">{children}</main>
            <footer className="border-t border-border/60 py-8 text-center text-sm text-muted-foreground">
                © {new Date().getFullYear()} {name} · {t('Made for institutions in Bangladesh')}
            </footer>
        </div>
    );
}
