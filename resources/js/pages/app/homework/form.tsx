import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import HomeworkController from '@/actions/App/Modules/Homework/Http/Controllers/HomeworkController';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, FormSection, SelectField, TextareaField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/homework';

type ClassOption = { id: number; name: string; sections: { id: number; name: string }[]; subjects: { id: number; name: string }[] };

export default function HomeworkForm({ options }: { homework: null; options: ClassOption[] }) {
    const t = useT();
    const [classId, setClassId] = useState(options.length === 1 ? String(options[0].id) : '');
    const current = options.find((c) => String(c.id) === classId);
    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title={t('Set homework')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Set homework')} description={t('Every enrolled student gets a row you can mark as submitted, late or graded.')} />
                {options.length === 0 ? (
                    <EmptyState title={t('No classes assigned to you')} description={t('Ask the office to assign you a class and subject.')} />
                ) : (
                    <Form {...HomeworkController.store.form()} className="grid max-w-3xl gap-5">
                        {({ processing, errors, progress }) => (
                            <FormSection title={t('Homework')}>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <SelectField label={t('Class')} name="class_id" placeholder={t('Choose…')} options={options.map((c) => ({ value: c.id, label: c.name }))} value={classId} onChange={(e) => setClassId(e.target.value)} required error={errors.class_id} />
                                    <SelectField label={t('Section')} name="section_id" placeholder={t('Whole class')} options={(current?.sections ?? []).map((s) => ({ value: s.id, label: s.name }))} error={errors.section_id} />
                                    <SelectField label={t('Subject')} name="subject_id" placeholder={t('Choose…')} options={(current?.subjects ?? []).map((s) => ({ value: s.id, label: s.name }))} required error={errors.subject_id} />
                                    <TextField label={t('Title')} name="title" required error={errors.title} fieldClassName="sm:col-span-3" />
                                    <TextareaField label={t('Instructions')} name="instructions" rows={5} error={errors.instructions} fieldClassName="sm:col-span-3" />
                                    <TextField label={t('Assigned on')} name="assigned_on" type="date" defaultValue={today} required error={errors.assigned_on} />
                                    <TextField label={t('Due')} name="due_at" type="datetime-local" error={errors.due_at} />
                                    <TextField label={t('Max marks')} name="max_marks" type="number" step="0.5" min={1} error={errors.max_marks} />
                                    <SelectField label={t('Status')} name="status" options={['published', 'draft'].map((s) => ({ value: s, label: t(s) }))} error={errors.status} />
                                    <Field label={t('Attachment')} name="attachment" error={errors.attachment} hint={t('PDF, Word, image, Excel or PowerPoint up to 10 MB. Stored privately.')} className="sm:col-span-2">
                                        <input id="attachment" name="attachment" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xlsx,.pptx,.txt" className="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5" />
                                    </Field>
                                </div>
                                {progress && <progress value={progress.percentage} max="100" className="mt-3 w-full" />}
                                <div className="mt-4 flex gap-2">
                                    <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                                    <Button type="button" variant="outline" asChild><Link href={index()}>{t('Cancel')}</Link></Button>
                                </div>
                            </FormSection>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

HomeworkForm.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Homework', href: index() }] };
