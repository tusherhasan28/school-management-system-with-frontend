import { usePage } from '@inertiajs/react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import PortalLayout from '@/layouts/portal-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth } = usePage().props;

    // Parents and students never see the staff sidebar (e.g. on account settings).
    if (auth?.area === 'parent' || auth?.area === 'student') {
        return <PortalLayout>{children}</PortalLayout>;
    }

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}
