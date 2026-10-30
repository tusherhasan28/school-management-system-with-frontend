import { Form, Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, Trash2 } from 'lucide-react';
import CalendarController from '@/actions/App/Modules/Teaching/Http/Controllers/CalendarController';
import { EmptyState } from '@/components/shared/empty-state';
import { FormSection, SelectField, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, useT, weekdayName } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index } from '@/routes/calendar';

type Holiday = { id: number; name: string; name_bn: string | null; holiday_type: string; start_date: string; end_date: string };

type Props = { year: number; holidays: Holiday[]; weekend: number[]; notifyAbsent: boolean; workingDays: number; can: { manage: boolean } };

const types = ['public', 'religious', 'school', 'exam_break', 'other'];

export default function Calendar({ year, holidays, weekend, notifyAbsent, workingDays, can }: Props) {
    const t = useT();
    const { locale } = usePage().props;

    return (
        <>
            <Head title={t('Calendar & holidays')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Calendar & holidays')}
                    description={t('Attendance cannot be taken on weekly off-days or holidays.')}
                    actions={<Input type="number" className="w-28" value={year} onChange={(e) => router.get(index.url(), { year: e.target.value }, { preserveState: true })} aria-label={t('Year')} />}
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard label={t('Working days in :year', { year })} value={workingDays} icon={CalendarDays} />
                    <StatCard label={t('Holidays')} value={holidays.length} tone="warning" />
                    <StatCard label={t('Weekly off')} value={weekend.map((d) => weekdayName(d, locale)).join(', ') || '—'} tone="success" />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        {holidays.length === 0 ? (
                            <EmptyState icon={CalendarDays} title={t('No holidays added for :year', { year })} />
                        ) : (
                            <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                                {holidays.map((h) => (
                                    <li key={h.id} className="flex items-center justify-between gap-2 px-4 py-3 text-sm">
                                        <div>
                                            <p className="font-medium">{locale === 'bn' && h.name_bn ? h.name_bn : h.name}</p>
                                            <p className="text-muted-foreground">{formatDate(h.start_date)}{h.end_date !== h.start_date ? ` → ${formatDate(h.end_date)}` : ''} · {t(h.holiday_type)}</p>
                                        </div>
                                        {can.manage && <Button size="icon" variant="ghost" aria-label={t('Delete :name', { name: h.name })} onClick={() => router.delete(CalendarController.destroyHoliday.url(h.id), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {can.manage && (
                        <div className="grid content-start gap-4">
                            <Form {...CalendarController.storeHoliday.form()} options={{ preserveScroll: true }} resetOnSuccess>
                                {({ processing, errors }) => (
                                    <FormSection title={t('Add holiday')}>
                                        <div className="grid gap-3">
                                            <TextField label={t('Name')} name="name" required error={errors.name} />
                                            <TextField label={t('Name (Bangla)')} name="name_bn" lang="bn" error={errors.name_bn} />
                                            <div className="grid grid-cols-2 gap-3">
                                                <TextField label={t('Starts')} name="start_date" type="date" required error={errors.start_date} />
                                                <TextField label={t('Ends')} name="end_date" type="date" required error={errors.end_date} />
                                            </div>
                                            <SelectField label={t('Type')} name="holiday_type" options={types.map((v) => ({ value: v, label: t(v) }))} error={errors.holiday_type} />
                                            <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Add')}</Button>
                                        </div>
                                    </FormSection>
                                )}
                            </Form>

                            <Form {...CalendarController.updateSettings.form()} options={{ preserveScroll: true }}>
                                {({ processing }) => (
                                    <FormSection title={t('Settings')}>
                                        <fieldset className="grid gap-2">
                                            <legend className="mb-1 text-sm font-medium">{t('Weekly off-days')}</legend>
                                            <div className="flex flex-wrap gap-3">
                                                {[7, 1, 2, 3, 4, 5, 6].map((d) => (
                                                    <label key={d} className="flex items-center gap-1.5 text-sm">
                                                        <input type="checkbox" name="weekend[]" value={d} defaultChecked={weekend.includes(d)} className="size-4 accent-primary" />
                                                        {weekdayName(d, locale)}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                        <label className="mt-4 flex items-center gap-2 text-sm">
                                            <input type="hidden" name="notify_absent" value="0" />
                                            <input type="checkbox" name="notify_absent" value="1" defaultChecked={notifyAbsent} className="size-4 accent-primary" />
                                            {t('Email the primary guardian when a student is marked absent')}
                                        </label>
                                        <Button type="submit" className="mt-4" disabled={processing}>{processing && <Spinner />}{t('Save')}</Button>
                                    </FormSection>
                                )}
                            </Form>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Calendar.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Calendar & holidays', href: index() }] };
