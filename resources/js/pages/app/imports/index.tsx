import { Form, Head, Link } from '@inertiajs/react';
import { Download, FileSpreadsheet, Upload } from 'lucide-react';
import { useState } from 'react';
import ImportController from '@/actions/App/Modules/Imports/Http/Controllers/ImportController';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, FormSection, SelectField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index, show, template } from '@/routes/imports';
import type { AcademicClass, Paginated, SessionOption } from '@/types';
import { ImportStatus } from './show';

export type ImportRow = {
    id: number; original_name: string; status: string; total_rows: number; valid_rows: number; error_rows: number; duplicate_rows: number;
    imported_rows: number; has_report: boolean; failure_message: string | null; user: string | null; created_at: string;
};

type Props = { imports: Paginated<ImportRow>; sessions: SessionOption[]; classes: AcademicClass[]; currentSessionId: number | null; columns: string[] };

export default function ImportsIndex({ imports, sessions, classes, currentSessionId, columns }: Props) {
    const t = useT();
    const [classId, setClassId] = useState('');
    const sections = classes.find((c) => String(c.id) === classId)?.sections ?? [];

    return (
        <>
            <Head title={t('Import students')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Import students')}
                    description={t('Upload a CSV or Excel file. You will see a preview with errors and duplicates before anything is saved.')}
                    actions={<Button variant="outline" asChild><a href={template.url()}><Download />{t('Download template')}</a></Button>}
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <Form {...ImportController.store.form()} className="lg:col-span-2">
                        {({ processing, errors, progress }) => (
                            <FormSection title={t('Upload')} description={t('All rows go into the class below. A "section" column can override the section per row.')}>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <SelectField label={t('Session')} name="academic_session_id" options={sessions.map((s) => ({ value: s.id, label: s.name }))} defaultValue={currentSessionId ?? ''} required error={errors.academic_session_id} />
                                    <SelectField label={t('Class')} name="class_id" placeholder={t('Choose…')} options={classes.map((c) => ({ value: c.id, label: c.name }))} value={classId} onChange={(e) => setClassId(e.target.value)} required error={errors.class_id} />
                                    <SelectField label={t('Section')} name="section_id" placeholder="—" options={sections.map((s) => ({ value: s.id, label: s.name }))} disabled={!sections.length} error={errors.section_id} />
                                    <Field label={t('File')} name="file" error={errors.file} required className="sm:col-span-3">
                                        <input id="file" name="file" type="file" required accept=".csv,.xlsx" className="block w-full rounded-md border border-dashed p-3 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-primary-foreground" />
                                    </Field>
                                </div>
                                {progress && <progress value={progress.percentage} max="100" className="mt-3 w-full" />}
                                <Button type="submit" className="mt-4" disabled={processing}>{processing ? <Spinner /> : <Upload />}{t('Upload and check')}</Button>
                            </FormSection>
                        )}
                    </Form>
                    <FormSection title={t('Columns')} className="self-start">
                        <ul className="grid gap-1 font-mono text-xs">
                            {columns.map((c) => <li key={c}>{c}{['full_name', 'guardian_name', 'guardian_phone'].includes(c) && <span className="text-destructive"> *</span>}</li>)}
                        </ul>
                        <p className="mt-3 text-xs text-muted-foreground">{t('Dates as YYYY-MM-DD. Mobile numbers as 01XXXXXXXXX. Up to 5,000 rows per file.')}</p>
                    </FormSection>
                </div>

                <section className="grid gap-3">
                    <h2 className="font-medium">{t('Recent imports')}</h2>
                    {imports.data.length === 0 ? (
                        <EmptyState icon={FileSpreadsheet} title={t('No imports yet')} />
                    ) : (
                        <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                            {imports.data.map((i) => (
                                <li key={i.id}>
                                    <Link href={show(i.id)} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-muted/30">
                                        <span className="font-medium">{i.original_name}</span>
                                        <span className="text-muted-foreground">{t(':valid valid · :errors errors · :dupes duplicates', { valid: i.valid_rows, errors: i.error_rows, dupes: i.duplicate_rows })}</span>
                                        <span className="flex items-center gap-2"><ImportStatus status={i.status} /><span className="text-muted-foreground">{formatDateTime(i.created_at)}</span></span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Pagination page={imports} />
                </section>
            </div>
        </>
    );
}

ImportsIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Import', href: index() }] };
