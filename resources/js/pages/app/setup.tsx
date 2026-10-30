import { Form, Head, router, useForm } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SetupController from '@/actions/App/Modules/Setup/Http/Controllers/SetupController';
import { FormSection, SelectField, TextareaField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show } from '@/routes/setup';

type Props = {
    progress: { completed: string[]; finished_at: string | null };
    steps: string[];
    profile: { name: string; name_bn: string | null; institution_type: string; email: string | null; phone: string | null; address: string | null; district: string | null; division: string | null; locale: string };
    sessionSuggestion: { name: string; start_date: string; end_date: string };
    currentSession: { id: number; name: string } | null;
    classPreset: { stage: string; classes: { name: string; name_bn: string }[] };
    subjectPreset: { name: string; name_bn: string; code: string }[];
    existing: { classes: string[]; subjects: string[] };
};

export default function Setup(props: Props) {
    const t = useT();
    const firstOpen = props.steps.find((s) => !props.progress.completed.includes(s)) ?? props.steps[props.steps.length - 1];
    const [step, setStep] = useState(firstOpen);
    const done = (s: string) => props.progress.completed.includes(s);
    const labels: Record<string, string> = { profile: t('School profile'), session: t('Academic session'), classes: t('Classes & sections'), subjects: t('Subjects') };
    const next = () => setStep(props.steps[Math.min(props.steps.indexOf(step) + 1, props.steps.length - 1)]);
    const allDone = props.steps.every(done);

    return (
        <>
            <Head title={t('Setup wizard')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Set up your school')} description={t('Four short steps. You can leave and continue later; progress is saved.')} />

                <ol className="grid gap-2 sm:grid-cols-4">
                    {props.steps.map((s, i) => (
                        <li key={s}>
                            <button
                                onClick={() => setStep(s)}
                                aria-current={step === s ? 'step' : undefined}
                                className={cn('flex w-full items-center gap-3 rounded-xl border p-3 text-left text-sm transition-colors', step === s ? 'border-primary bg-primary/5' : 'border-border/70 bg-card hover:bg-muted/40')}
                            >
                                <span className={cn('flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-medium', done(s) ? 'bg-primary text-primary-foreground' : 'bg-muted')}>
                                    {done(s) ? <Check className="size-4" /> : i + 1}
                                </span>
                                {labels[s]}
                            </button>
                        </li>
                    ))}
                </ol>

                <div className="max-w-4xl">
                    {step === 'profile' && (
                        <Form {...SetupController.profile.form()} options={{ preserveScroll: true }} onSuccess={next}>
                            {({ processing, errors }) => (
                                <FormSection title={labels.profile} description={t('Shown on your website and on printed documents.')}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextField label={t('Institution name (English)')} name="name" defaultValue={props.profile.name} required error={errors.name} />
                                        <TextField label={t('Institution name (Bangla)')} name="name_bn" lang="bn" defaultValue={props.profile.name_bn ?? ''} error={errors.name_bn} />
                                        <SelectField label={t('Institution type')} name="institution_type" options={['school', 'college', 'kindergarten', 'madrasa', 'combined', 'other'].map((v) => ({ value: v, label: t(v) }))} defaultValue={props.profile.institution_type} error={errors.institution_type} />
                                        <SelectField label={t('Default language')} name="locale" options={[{ value: 'bn', label: 'বাংলা' }, { value: 'en', label: 'English' }]} defaultValue={props.profile.locale} error={errors.locale} />
                                        <TextField label={t('Phone')} name="phone" type="tel" defaultValue={props.profile.phone ?? ''} error={errors.phone} />
                                        <TextField label={t('Email')} name="email" type="email" defaultValue={props.profile.email ?? ''} error={errors.email} />
                                        <TextField label={t('District')} name="district" defaultValue={props.profile.district ?? ''} error={errors.district} />
                                        <TextField label={t('Division')} name="division" defaultValue={props.profile.division ?? ''} error={errors.division} />
                                        <TextareaField label={t('Address')} name="address" defaultValue={props.profile.address ?? ''} error={errors.address} fieldClassName="sm:col-span-2" />
                                    </div>
                                    <Button type="submit" className="mt-4" disabled={processing}>{processing && <Spinner />}{t('Save and continue')}</Button>
                                </FormSection>
                            )}
                        </Form>
                    )}

                    {step === 'session' && (
                        <Form {...SetupController.session.form()} options={{ preserveScroll: true }} onSuccess={next}>
                            {({ processing, errors }) => (
                                <FormSection title={labels.session} description={props.currentSession ? t('Current session: :name', { name: props.currentSession.name }) : t('The academic year your classes and fees belong to.')}>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <TextField label={t('Name')} name="name" defaultValue={props.currentSession?.name ?? props.sessionSuggestion.name} required error={errors.name} />
                                        <TextField label={t('Starts')} name="start_date" type="date" defaultValue={props.sessionSuggestion.start_date} required error={errors.start_date} />
                                        <TextField label={t('Ends')} name="end_date" type="date" defaultValue={props.sessionSuggestion.end_date} required error={errors.end_date} />
                                    </div>
                                    <Button type="submit" className="mt-4" disabled={processing}>{processing && <Spinner />}{t('Save and continue')}</Button>
                                </FormSection>
                            )}
                        </Form>
                    )}

                    {step === 'classes' && <ClassesStep preset={props.classPreset} existing={props.existing.classes} onDone={next} />}
                    {step === 'subjects' && <SubjectsStep preset={props.subjectPreset} existing={props.existing.subjects} />}

                    {allDone && !props.progress.finished_at && (
                        <div className="mt-6 flex items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4">
                            <p className="text-sm">{t('All steps are complete. You can change everything later under Academics.')}</p>
                            <Button onClick={() => router.post(SetupController.finish.url())}>{t('Finish setup')}</Button>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

function ClassesStep({ preset, existing, onDone }: { preset: Props['classPreset']; existing: string[]; onDone: () => void }) {
    const t = useT();
    const form = useForm({
        stage: preset.stage,
        classes: preset.classes.filter((c) => !existing.includes(c.name)).map((c) => ({ ...c, sections: 'A' })),
    });

    const update = (i: number, patch: Partial<(typeof form.data.classes)[number]>) =>
        form.setData('classes', form.data.classes.map((c, j) => (j === i ? { ...c, ...patch } : c)));

    const submit = () => {
        form.transform((d) => ({ ...d, classes: d.classes.map((c) => ({ ...c, sections: c.sections.split(',').map((s) => s.trim()).filter(Boolean) })) }));
        form.post(SetupController.classes.url(), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <FormSection title={t('Classes & sections')} description={t('A suggested list for your institution type. Rename, remove or add rows. Sections are comma separated (e.g. A, B).')}>
            {existing.length > 0 && <p className="mb-3 text-sm text-muted-foreground">{t('Already created: :list', { list: existing.join(', ') })}</p>}
            <div className="grid gap-2">
                {form.data.classes.map((c, i) => (
                    <div key={i} className="grid grid-cols-[1fr_1fr_6rem_auto] items-center gap-2">
                        <Input value={c.name} onChange={(e) => update(i, { name: e.target.value })} aria-label={t('Name')} />
                        <Input value={c.name_bn} lang="bn" onChange={(e) => update(i, { name_bn: e.target.value })} aria-label={t('Name (Bangla)')} />
                        <Input value={c.sections} onChange={(e) => update(i, { sections: e.target.value })} aria-label={t('Sections')} />
                        <Button size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => form.setData('classes', form.data.classes.filter((_, j) => j !== i))}><Trash2 /></Button>
                    </div>
                ))}
            </div>
            {Object.values(form.errors).slice(0, 3).map((e) => <p key={e} className="mt-2 text-sm text-destructive">{e}</p>)}
            <div className="mt-4 flex flex-wrap gap-2">
                <Button variant="outline" onClick={() => form.setData('classes', [...form.data.classes, { name: '', name_bn: '', sections: 'A' }])}><Plus />{t('Add row')}</Button>
                <Button onClick={submit} disabled={form.processing || form.data.classes.length === 0}>{form.processing && <Spinner />}{t('Save and continue')}</Button>
            </div>
        </FormSection>
    );
}

function SubjectsStep({ preset, existing }: { preset: Props['subjectPreset']; existing: string[] }) {
    const t = useT();
    const form = useForm({ subjects: preset.filter((s) => !existing.includes(s.name)) });

    const update = (i: number, patch: Partial<(typeof form.data.subjects)[number]>) =>
        form.setData('subjects', form.data.subjects.map((s, j) => (j === i ? { ...s, ...patch } : s)));

    return (
        <FormSection title={t('Subjects')} description={t('Subjects taught in your institution. Assign them to classes later with full and pass marks.')}>
            {existing.length > 0 && <p className="mb-3 text-sm text-muted-foreground">{t('Already created: :list', { list: existing.join(', ') })}</p>}
            <div className="grid gap-2">
                {form.data.subjects.map((s, i) => (
                    <div key={i} className="grid grid-cols-[1fr_1fr_6rem_auto] items-center gap-2">
                        <Input value={s.name} onChange={(e) => update(i, { name: e.target.value })} aria-label={t('Name')} />
                        <Input value={s.name_bn} lang="bn" onChange={(e) => update(i, { name_bn: e.target.value })} aria-label={t('Name (Bangla)')} />
                        <Input value={s.code} onChange={(e) => update(i, { code: e.target.value })} aria-label={t('Code')} />
                        <Button size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => form.setData('subjects', form.data.subjects.filter((_, j) => j !== i))}><Trash2 /></Button>
                    </div>
                ))}
            </div>
            {Object.values(form.errors).slice(0, 3).map((e) => <p key={e} className="mt-2 text-sm text-destructive">{e}</p>)}
            <div className="mt-4 flex flex-wrap gap-2">
                <Button variant="outline" onClick={() => form.setData('subjects', [...form.data.subjects, { name: '', name_bn: '', code: '' }])}><Plus />{t('Add row')}</Button>
                <Button onClick={() => form.post(SetupController.subjects.url(), { preserveScroll: true })} disabled={form.processing || form.data.subjects.length === 0}>{form.processing && <Spinner />}{t('Save')}</Button>
            </div>
        </FormSection>
    );
}

Setup.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Setup wizard', href: show() }] };
