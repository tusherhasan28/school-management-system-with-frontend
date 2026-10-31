import { Link, usePage } from '@inertiajs/react';
import { Award, Banknote, BookOpen, Building2, CalendarDays, CalendarRange, ClipboardCheck, ClipboardList, FilePen, FileUp, GraduationCap, LayoutGrid, ListChecks, NotebookPen, Settings2, UserCog, UserRound, Users } from 'lucide-react';
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
import { index as assignments } from '@/routes/assignments';
import { index as attendance } from '@/routes/attendance';
import { index as calendar } from '@/routes/calendar';
import { index as exams, setup as examSetup } from '@/routes/exams';
import { dashboard as fees } from '@/routes/fees';
import { index as homework } from '@/routes/homework';
import { index as marks } from '@/routes/marks';
import { index as timetable, mine as myTimetable } from '@/routes/timetable';
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
        ...(can('fees.view') || can('fees.collect') ? [{ title: t('Fees'), href: fees(), icon: Banknote }] : []),
        ...when('students.view', { title: t('Students'), href: students(), icon: GraduationCap }),
        ...when('guardians.view', { title: t('Guardians'), href: guardians(), icon: Users }),
        ...when('staff.view', { title: t('Teachers & staff'), href: staff(), icon: UserRound }),
    ];

    const teachingItems: NavItem[] = [
        ...(can('attendance.mark') || can('attendance.view_all') ? [{ title: t('Attendance'), href: attendance(), icon: ClipboardCheck }] : []),
        ...(can('homework.manage') || can('homework.view_all') ? [{ title: t('Homework'), href: homework(), icon: NotebookPen }] : []),
        ...(auth.isStaff ? [{ title: t('My timetable'), href: myTimetable(), icon: CalendarRange }] : []),
        ...when('timetable.view', { title: t('Class timetable'), href: timetable(), icon: CalendarRange }),
        ...when('assignments.manage', { title: t('Teacher assignments'), href: assignments(), icon: UserCog }),
        { title: t('Calendar & holidays'), href: calendar(), icon: CalendarDays },
    ];

    const examItems: NavItem[] = [
        ...(can('marks.enter') || can('marks.enter_all') || can('marks.verify') ? [{ title: t('Marks entry'), href: marks(), icon: FilePen }] : []),
        ...(['exams.manage', 'results.view', 'marks.verify', 'marks.enter_all', 'results.approve', 'results.publish'].some(can) ? [{ title: t('Exams & results'), href: exams(), icon: Award }] : []),
        ...when('exams.manage', { title: t('Exam setup'), href: examSetup(), icon: Settings2 }),
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
                <NavMain items={teachingItems} label={t('Teaching')} />
                {examItems.length > 0 && <NavMain items={examItems} label={t('Examinations')} />}
                {setupItems.length > 0 && <NavMain items={setupItems} label={t('School setup')} />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
