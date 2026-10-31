import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { useT } from '@/lib/i18n';

export default function NoChildren() {
    const t = useT();

    return (
        <>
            <Head title={t('Parent portal')} />
            <EmptyState icon={Users} title={t('No students are linked to your account yet')} description={t('Please contact the school office to link your child.')} />
        </>
    );
}
