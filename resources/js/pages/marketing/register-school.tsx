import { Form, Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Field, FormSection, SelectField, TextareaField, TextField } from '@/components/shared/fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';

type Props = { domain: string; submitted: boolean };

/** Marketing routes are root-domain bound, so plain paths are used (see marketing-layout). */
export default function RegisterSchool({ domain, submitted }: Props) {
    const t = useT();
    const [slug, setSlug] = useState('');
    const [available, setAvailable] = useState<boolean | null>(null);

    useEffect(() => {
        if (slug.length < 3) {
            setAvailable(null);

            return;
        }

        const id = setTimeout(() => {
            fetch(`/register-school/check-slug?slug=${encodeURIComponent(slug)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d: { available: boolean }) => setAvailable(d.available))
                .catch(() => setAvailable(null));
        }, 400);

        return () => clearTimeout(id);
    }, [slug]);

    return (
        <>
            <Head title={t('Register your school')} />
            <section className="mx-auto grid max-w-3xl gap-6 px-4 py-12 sm:px-6">
                <div>
                    <h1 className="text-3xl font-semibold tracking-tight">{t('Register your school')}</h1>
                    <p className="mt-2 text-muted-foreground">{t('Free trial. We review every registration and email you a link to set up your account.')}</p>
                </div>

                {submitted ? (
                    <div className="flex gap-3 rounded-xl border border-emerald-600/30 bg-emerald-50 p-5 dark:bg-emerald-500/10">
                        <CheckCircle2 className="size-6 shrink-0 text-emerald-600" />
                        <p>{t('Thank you! We will review your registration and email you shortly.')}</p>
                    </div>
                ) : (
                    <Form action="/register-school" method="post" className="grid gap-5">
                        {({ processing, errors }) => (
                            <>
                                <FormSection title={t('Institution')}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextField label={t('Institution name (English)')} name="institution_name" required error={errors.institution_name} />
                                        <TextField label={t('Institution name (Bangla)')} name="institution_name_bn" lang="bn" error={errors.institution_name_bn} />
                                        <SelectField label={t('Institution type')} name="institution_type" options={['school', 'college', 'kindergarten', 'madrasa', 'combined', 'other'].map((v) => ({ value: v, label: t(v) }))} error={errors.institution_type} />
                                        <TextField label={t('Approximate number of students')} name="approx_students" type="number" min={1} error={errors.approx_students} />
                                        <Field label={t('Web address')} name="requested_slug" required error={errors.requested_slug} hint={available === null ? t('Lowercase letters, numbers and hyphens.') : available ? t('Available') : t('Not available')} className="sm:col-span-2">
                                            <div className="flex items-center rounded-md border focus-within:ring-2 focus-within:ring-ring">
                                                <Input id="requested_slug" name="requested_slug" value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} className="border-0 shadow-none focus-visible:ring-0" placeholder="dhaka-model" required />
                                                <span className="pr-3 text-sm whitespace-nowrap text-muted-foreground">.{domain}</span>
                                            </div>
                                        </Field>
                                        <TextField label={t('District')} name="district" error={errors.district} />
                                        <TextareaField label={t('Address')} name="address" error={errors.address} fieldClassName="sm:col-span-2" />
                                    </div>
                                </FormSection>
                                <FormSection title={t('Contact person')} description={t('Becomes the school owner account.')}>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <TextField label={t('Name')} name="contact_name" required error={errors.contact_name} />
                                        <TextField label={t('Email')} name="email" type="email" required error={errors.email} />
                                        <TextField label={t('Mobile')} name="phone" type="tel" placeholder="01XXXXXXXXX" required error={errors.phone} />
                                    </div>
                                </FormSection>
                                <input type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden />
                                <Button type="submit" size="lg" disabled={processing || available === false}>{processing && <Spinner />}{t('Submit registration')}</Button>
                            </>
                        )}
                    </Form>
                )}
            </section>
        </>
    );
}
