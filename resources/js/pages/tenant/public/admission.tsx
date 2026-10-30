import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import PublicAdmissionController from '@/actions/App/Modules/Admissions/Http/Controllers/PublicAdmissionController';
import { FormSection, SelectField, TextareaField, TextField } from '@/components/shared/fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { home } from '@/routes';

type Props = {
    school: { name: string; name_bn: string | null };
    session: string | null;
    classes: { id: number; name: string; name_bn: string | null }[];
    submittedNo: string | null;
};

/** Public admission form on the school website. No login required. */
export default function PublicAdmission({ school, session, classes, submittedNo }: Props) {
    const t = useT();
    const { locale } = usePage().props;
    const name = locale === 'bn' && school.name_bn ? school.name_bn : school.name;
    const label = (c: Props['classes'][number]) => (locale === 'bn' && c.name_bn ? c.name_bn : c.name);

    return (
        <div className="min-h-svh bg-muted/30">
            <Head title={`${t('Admission')} — ${name}`}>
                <meta name="description" content={t('Apply for admission to :name', { name })} />
            </Head>
            <header className="border-b border-border/60 bg-background">
                <div className="mx-auto flex h-16 max-w-3xl items-center justify-between px-4">
                    <Link href={home()} className="font-semibold">{name}</Link>
                </div>
            </header>
            <main className="mx-auto grid max-w-3xl gap-6 px-4 py-8">
                <div>
                    <h1 className="text-2xl font-semibold">{t('Admission application')}</h1>
                    {session && <p className="text-muted-foreground">{t('Session :name', { name: session })}</p>}
                </div>

                {submittedNo ? (
                    <div className="flex gap-3 rounded-xl border border-emerald-600/30 bg-emerald-50 p-5 dark:bg-emerald-500/10">
                        <CheckCircle2 className="size-6 shrink-0 text-emerald-600" />
                        <div>
                            <p className="font-medium">{t('Application submitted')}</p>
                            <p className="text-sm">{t('Your application number is :no. Keep it for future contact with the school.', { no: submittedNo })}</p>
                        </div>
                    </div>
                ) : classes.length === 0 ? (
                    <p className="rounded-xl border bg-card p-5 text-sm">{t('Admissions are not open online yet. Please contact the school office.')}</p>
                ) : (
                    <Form {...PublicAdmissionController.store.form()} className="grid gap-5">
                        {({ processing, errors }) => (
                            <>
                                <FormSection title={t('Applicant')}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextField label={t('Full name (English)')} name="applicant_name" required error={errors.applicant_name} />
                                        <TextField label={t('Full name (Bangla)')} name="applicant_name_bn" lang="bn" error={errors.applicant_name_bn} />
                                        <TextField label={t('Date of birth')} name="date_of_birth" type="date" required error={errors.date_of_birth} />
                                        <SelectField label={t('Gender')} name="gender" placeholder={t('Choose…')} options={['male', 'female', 'other'].map((g) => ({ value: g, label: t(g) }))} required error={errors.gender} />
                                        <SelectField label={t('Class applying for')} name="applied_class_id" placeholder={t('Choose…')} options={classes.map((c) => ({ value: c.id, label: label(c) }))} required error={errors.applied_class_id} />
                                        <TextField label={t('Previous school')} name="previous_school" error={errors.previous_school} />
                                        <TextField label={t("Father's name")} name="father_name" error={errors.father_name} />
                                        <TextField label={t("Mother's name")} name="mother_name" error={errors.mother_name} />
                                        <TextField label={t('Religion')} name="religion" error={errors.religion} />
                                        <SelectField label={t('Blood group')} name="blood_group" placeholder="—" options={['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((b) => ({ value: b, label: b }))} error={errors.blood_group} />
                                    </div>
                                </FormSection>
                                <FormSection title={t('Guardian')}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextField label={t('Guardian name')} name="guardian_name" required error={errors.guardian_name} />
                                        <SelectField label={t('Relationship')} name="guardian_relationship" options={['father', 'mother', 'guardian', 'other'].map((r) => ({ value: r, label: t(r) }))} required error={errors.guardian_relationship} />
                                        <TextField label={t('Mobile')} name="guardian_phone" type="tel" placeholder="01XXXXXXXXX" required error={errors.guardian_phone} />
                                        <TextField label={t('Email')} name="guardian_email" type="email" error={errors.guardian_email} />
                                        <TextareaField label={t('Address')} name="address" required error={errors.address} fieldClassName="sm:col-span-2" />
                                    </div>
                                </FormSection>
                                {/* Honeypot: hidden from people, filled by bots. */}
                                <input type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden />
                                <Button type="submit" size="lg" disabled={processing}>{processing && <Spinner />}{t('Submit application')}</Button>
                            </>
                        )}
                    </Form>
                )}
            </main>
        </div>
    );
}
