import { Link, router, usePage } from '@inertiajs/react';
import { BookOpen, CalendarCheck, CalendarRange, GraduationCap, Home, Megaphone, Wallet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export const portalTabs: { key: string; label: string; icon: LucideIcon }[] = [
    { key: 'overview', label: 'Overview', icon: Home },
    { key: 'attendance', label: 'Attendance', icon: CalendarCheck },
    { key: 'timetable', label: 'Timetable', icon: CalendarRange },
    { key: 'homework', label: 'Homework', icon: BookOpen },
    { key: 'results', label: 'Results', icon: GraduationCap },
    { key: 'fees', label: 'Fees', icon: Wallet },
    { key: 'notices', label: 'Notices', icon: Megaphone },
];

export function tabUrl(base: string, tab: string) {
    return tab === 'overview' ? base : `${base}/${tab}`;
}

type PortalPageProps = { base?: string; tab?: string; student?: { id: number } };

/**
 * Mobile-first shell for the parent and student portals: a slim top bar
 * (school, child switcher, account) and, on phones, a bottom tab bar.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const page = usePage<PortalPageProps>();
    const { auth, school, locale } = page.props;
    const getInitials = useInitials();
    const base = page.props.base;
    const active = page.props.tab;
    const kids = auth.area === 'parent' ? auth.children : [];
    const current = page.props.student?.id;
    const schoolName = locale === 'bn' && school?.name_bn ? school.name_bn : school?.name;

    return (
        <div className="min-h-svh bg-muted/30 pb-20 md:pb-8">
            <header className="sticky top-0 z-30 border-b border-border/70 bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-5xl items-center gap-3 px-4">
                    <Link href="/home" className="min-w-0 flex-1 truncate font-semibold">{schoolName}</Link>
                    {kids.length > 1 && (
                        <label className="flex items-center gap-2 text-sm">
                            <span className="sr-only">{t('Child')}</span>
                            <select
                                className="h-9 max-w-[11rem] rounded-md border border-input bg-background px-2 text-sm"
                                value={current ?? ''}
                                onChange={(e) => router.visit(`/parent/children/${e.target.value}${active && active !== 'overview' ? `/${active}` : ''}`)}
                            >
                                {kids.map((k) => (
                                    <option key={k.id} value={k.id}>{locale === 'bn' && k.name_bn ? k.name_bn : k.name}</option>
                                ))}
                            </select>
                        </label>
                    )}
                    <DropdownMenu>
                        <DropdownMenuTrigger className="rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" aria-label={t('Account')}>
                            <Avatar className="size-8">
                                <AvatarFallback className="bg-primary/10 text-xs text-primary">{getInitials(auth.user.name)}</AvatarFallback>
                            </Avatar>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-56" align="end">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                {base && (
                    <nav className="mx-auto hidden max-w-5xl gap-1 overflow-x-auto px-4 md:flex" aria-label={t('Portal')}>
                        {portalTabs.map((tab) => (
                            <Link
                                key={tab.key}
                                href={tabUrl(base, tab.key)}
                                className={cn(
                                    'border-b-2 px-3 py-2.5 text-sm whitespace-nowrap transition-colors',
                                    active === tab.key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {t(tab.label)}
                            </Link>
                        ))}
                    </nav>
                )}
            </header>

            <main className="mx-auto max-w-5xl px-4 py-5">{children}</main>

            {base && (
                <nav className="fixed inset-x-0 bottom-0 z-30 border-t border-border/70 bg-background/95 backdrop-blur md:hidden" aria-label={t('Portal')}>
                    <div className="grid grid-cols-7">
                        {portalTabs.map((tab) => (
                            <Link
                                key={tab.key}
                                href={tabUrl(base, tab.key)}
                                className={cn('flex flex-col items-center gap-0.5 py-2 text-[10px] leading-tight', active === tab.key ? 'text-primary' : 'text-muted-foreground')}
                                aria-current={active === tab.key ? 'page' : undefined}
                            >
                                <tab.icon className="size-5" aria-hidden />
                                <span className="max-w-full truncate px-0.5">{t(tab.label)}</span>
                            </Link>
                        ))}
                    </div>
                </nav>
            )}
        </div>
    );
}
