import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { SimplePage } from './simple-page';

/** Each school signs in on its own subdomain; this page sends users there. */
export default function FindSchool() {
    const t = useT();
    const [slug, setSlug] = useState('');
    const host = typeof window !== 'undefined' ? window.location.host : '';
    const clean = slug.trim().toLowerCase().replace(/[^a-z0-9-]/g, '');

    return (
        <SimplePage title={t('Log in')} intro={t('Enter your school’s web address to continue to its sign-in page.')}>
            <form
                className="flex max-w-md flex-col gap-3 sm:flex-row sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();

                    if (clean) {
                        window.location.href = `${window.location.protocol}//${clean}.${host}/login`;
                    }
                }}
            >
                <div className="grid flex-1 gap-2">
                    <Label htmlFor="slug">{t('School address')}</Label>
                    <div className="flex items-center rounded-md border focus-within:ring-2 focus-within:ring-ring">
                        <Input id="slug" value={slug} onChange={(e) => setSlug(e.target.value)} className="border-0 shadow-none focus-visible:ring-0" placeholder="dhaka-model" />
                        <span className="pr-3 text-sm whitespace-nowrap text-muted-foreground">.{host}</span>
                    </div>
                </div>
                <Button type="submit" disabled={!clean}>{t('Continue')}</Button>
            </form>
        </SimplePage>
    );
}
