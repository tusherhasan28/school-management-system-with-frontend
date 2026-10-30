import { Link, router, usePage } from '@inertiajs/react';
import { LayoutGrid, LogOut, School } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { dashboard, logout } from '@/routes/platform';
import { index as schools } from '@/routes/platform/schools';
import type { BreadcrumbItem } from '@/types';

export default function PlatformLayout({
    children,
    breadcrumbs = [],
}: {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}) {
    const t = useT();
    const { auth } = usePage().props;

    return (
        <AppShell variant="sidebar">
            <Sidebar collapsible="icon" variant="inset">
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={dashboard()} prefetch>
                                    <span className="flex aspect-square size-8 items-center justify-center rounded-md bg-slate-900 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">
                                        P
                                    </span>
                                    <span className="grid flex-1 text-left text-sm leading-tight">
                                        <span className="truncate font-semibold">
                                            {t('Platform admin')}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {auth.platformUser?.role}
                                        </span>
                                    </span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>
                <SidebarContent>
                    <NavMain
                        label={t('Platform')}
                        items={[
                            { title: t('Dashboard'), href: dashboard(), icon: LayoutGrid },
                            { title: t('Schools'), href: schools(), icon: School },
                        ]}
                    />
                </SidebarContent>
                <SidebarFooter>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                onClick={() => router.post(logout.url())}
                                tooltip={{ children: t('Log out') }}
                            >
                                <LogOut />
                                <span className="truncate">
                                    {auth.platformUser?.name} · {t('Log out')}
                                </span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarFooter>
            </Sidebar>
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
