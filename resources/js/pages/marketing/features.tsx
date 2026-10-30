import { useT } from '@/lib/i18n';
import { SimplePage } from './simple-page';

export default function Features() {
    const t = useT();

    return <SimplePage title={t('Features')} intro={t('A detailed feature tour is coming soon.')} />;
}
