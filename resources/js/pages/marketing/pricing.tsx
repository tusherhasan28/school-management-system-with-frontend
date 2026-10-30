import { useT } from '@/lib/i18n';
import { SimplePage } from './simple-page';

export default function Pricing() {
    const t = useT();

    return <SimplePage title={t('Pricing')} intro={t('Plans are configured by the platform and shown here in BDT once published.')} />;
}
