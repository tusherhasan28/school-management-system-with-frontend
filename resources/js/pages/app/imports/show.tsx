import { Form, Head, router } from '@inertiajs/react';
import { Download, Play, RefreshCw } from 'lucide-react';
import { useEffect } from 'react';
import ImportController from '@/actions/App/Modules/Imports/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { errors as errorReport, index } from '@/routes/imports';
import type { ImportRow } from './index';

type Props = { import: ImportRow & { preview: { line: number; status: string; full_name: string; guardian_phone: string; messages: string[] }[] } };

const tone: Record<string, string> = { validated: 'trial', queued: 'pending', processing: 'pending', completed: 'active', failed: 'suspended', valid: 'active', duplicate: 'pending', error: 'suspended' };

export function ImportStatus({ status }: { status: string }) {
    const t = useT();

    return <StatusBadge status={tone[status] ?? 'archived'} label={t(status)} />;
}

export default function ImportShow({ import: job }: Props) {
    const t = useT();
    const running = job.status === 'queued' || job.status === 'processing';

    // Poll while the queued import runs.
    useEffect(() => {
        if (!running) {
            return;
        }

        const id = setInterval(() => router.reload({ only: ['import'] }), 3000);

        return () => clearInterval(id);
    }, [running]);

    return (
        <>
            <Head title={job.original_name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={job.original_name}
                    description={job.user ?? undefined}
                    actions={
                        <>
                            <ImportStatus status={job.status} />
                            {job.has_report && <Button variant="outline" asChild><a href={errorReport.url(job.id)}><Download />{t('Problem rows (CSV)')}</a></Button>}
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label={t('Rows')} value={job.total_rows} />
                    <StatCard label={t('Ready to import')} value={job.valid_rows} tone="success" />
                    <StatCard label={t('Duplicates (skipped)')} value={job.duplicate_rows} tone="warning" />
                    <StatCard label={t('Errors')} value={job.error_rows} tone="danger" />
                </div>

                {job.status === 'validated' && (
                    <Form {...ImportController.confirm.form(job.id)} className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4">
                        {({ processing, errors }) => (
                            <>
                                <p className="flex-1 text-sm">{t(':count students will be created. Duplicates and rows with errors are skipped.', { count: job.valid_rows })}</p>
                                <Button type="submit" disabled={processing || job.valid_rows === 0}>{processing ? <Spinner /> : <Play />}{t('Start import')}</Button>
                                <InputError message={errors.import} />
                            </>
                        )}
                    </Form>
                )}
                {running && <p className="flex items-center gap-2 text-sm text-muted-foreground"><RefreshCw className="size-4 animate-spin" />{t('Importing… this page refreshes automatically.')}</p>}
                {job.status === 'completed' && <p className="rounded-xl border border-emerald-600/30 bg-emerald-50 p-4 text-sm dark:bg-emerald-500/10">{t(':count students imported.', { count: job.imported_rows })}</p>}
                {job.failure_message && <p className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">{job.failure_message}</p>}

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <h2 className="border-b border-border/70 px-4 py-3 font-medium">{t('Preview (first rows)')}</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr><th className="px-4 py-2">{t('Line')}</th><th className="px-4 py-2">{t('Name')}</th><th className="px-4 py-2">{t('Guardian mobile')}</th><th className="px-4 py-2">{t('Result')}</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {job.preview.map((r) => (
                                    <tr key={r.line}>
                                        <td className="px-4 py-2 tabular-nums text-muted-foreground">{r.line}</td>
                                        <td className="px-4 py-2">{r.full_name || '—'}</td>
                                        <td className="px-4 py-2 tabular-nums">{r.guardian_phone || '—'}</td>
                                        <td className="px-4 py-2"><ImportStatus status={r.status} />{r.messages.length > 0 && <p className="mt-1 text-xs text-muted-foreground">{r.messages.join(' ')}</p>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

ImportShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Import', href: index() }] };
