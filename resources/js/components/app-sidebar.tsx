import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Building2, ClipboardList, FileUp, GraduationCap, LayoutGrid, ListChecks, UserRound, Users } from 'lucide-react';
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
import { index as academics } from '@/routes/academics';
import { index as admissions } from '@/routes/admissions';
import { index as campuses } from '@/routes/campuses';
import { index as guardians } from '@/routes/guardians';
import { index as imports } from '@/routes/imports';
import { show as setup } from '@/routes/setup';
import { index as staff } from '@/routes/staff';
import { index as students } from '@/routes/students';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const t = useT();
    const { school, auth, locale } = usePage().props;
    const can = (permission: string) => auth.permissions?.includes(permission);

    const when = (permission: string, item: NavItem) => (can(permission) ? [item] : []);

    const items: NavItem[] = [
        { title: t('Dashboard'), href: dashboard(), icon: LayoutGrid },
        ...when('admissions.manage', { title: t('Admissions'), href: admissions(), icon: ClipboardList }),
        ...when('students.view', { title: t('Students'), href: students(), icon: GraduationCap }),
        ...when('guardians.view', { title: t('Guardians'), href: guardians(), icon: Users }),
        ...when('staff.view', { title: t('Teachers & staff'), href: staff(), icon: UserRound }),
    ];

    const setupItems: NavItem[] = [
        ...when('academics.view', { title: t('Academics'), href: academics(), icon: BookOpen }),
        ...when('campuses.view', { title: t('Campuses'), href: campuses(), icon: Building2 }),
        ...when('imports.manage', { title: t('Import'), href: imports(), icon: FileUp }),
        ...when('settings.manage', { title: t('Setup wizard'), href: setup(), icon: ListChecks }),
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
                {setupItems.length > 0 && <NavMain items={setupItems} label={t('School setup')} />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
