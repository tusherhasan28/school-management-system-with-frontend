import { useT } from '@/lib/i18n';
import { SimplePage } from './simple-page';

export default function RegisterSchool() {
    const t = useT();

    return <SimplePage title={t('Register your school')} intro={t('Online registration opens soon. Contact us to be onboarded as a pilot school.')} />;
}
