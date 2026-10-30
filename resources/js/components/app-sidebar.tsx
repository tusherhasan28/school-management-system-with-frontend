import { Link, usePage } from '@inertiajs/react';
import { Building2, LayoutGrid } from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
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
import { dashboard } from '@/routes';
import { index as campuses } from '@/routes/campuses';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const t = useT();
    const { school, auth, locale } = usePage().props;
    const can = (permission: string) => auth.permissions?.includes(permission);

    const items: NavItem[] = [
        { title: t('Dashboard'), href: dashboard(), icon: LayoutGrid },
        ...(can('campuses.view')
            ? [{ title: t('Campuses'), href: campuses(), icon: Building2 }]
            : []),
    ];

    const schoolName =
        locale === 'bn' && school?.name_bn ? school.name_bn : school?.name;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <span className="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-sm font-semibold text-primary-foreground">
                                    {(school?.name ?? 'S').charAt(0)}
                                </span>
                                <span className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">
                                        {schoolName}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {t('School administration')}
                                    </span>
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} label={t('Manage')} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
