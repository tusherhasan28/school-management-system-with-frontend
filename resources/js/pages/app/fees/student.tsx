import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Printer, Receipt, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import InvoiceController from '@/actions/App/Modules/Finance/Http/Controllers/InvoiceController';
import PaymentController from '@/actions/App/Modules/Finance/Http/Controllers/PaymentController';
import StudentFeeController from '@/actions/App/Modules/Finance/Http/Controllers/StudentFeeController';
import InputError from '@/components/input-error';
import { Field, FormSection, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard as home } from '@/routes';
import { dashboard } from '@/routes/fees';
import { show as invoiceRoute } from '@/routes/fees/invoices';
import { receipt } from '@/routes/fees/payments';
import { show as studentProfile } from '@/routes/students';
import { InvoiceStatus, PaymentStatus, periodLabel } from './shared';
import type { Invoice, Payment } from './shared';

type OpenInvoice = { id: number; invoice_no: string; due_date: string; balance: string; period: string | null };

type Props = {
    student: { id: number; name: string; name_bn: string | null; admission_no: string; class: string | null; section: string | null; roll: string | null };
    summary: { due: string; overdue: string; paid_this_session: string };
    invoices: Invoice[];
    openInvoices: OpenInvoice[];
    payments: Payment[];
    assignments: { id: number; structure: string; category: string | null; default_amount: string; custom_amount: string | null; note: string | null; is_active: boolean; applies_to: string }[];
    discounts: { id: number; name: string; kind: string; value: string; is_active: boolean; category: string | null }[];
    options: { structures: { id: number; name: string; amount: string; applies_to: string }[]; categories: { id: number; name: string }[] };
    today: string;
    can: { collect: boolean; manage: boolean };
};

export default function StudentFees(props: Props) {
    const { student, summary, invoices, payments, assignments, discounts, can } = props;
    const t = useT();
    const { locale } = usePage().props;
    const [lastReceipt, setLastReceipt] = useState<number | null>(null);

    useEffect(() => router.on('flash', (e) => {
        const id = (e as CustomEvent).detail?.flash?.receipt;

        if (id) {
            setLastReceipt(Number(id));
        }
    }), []);

    return (
        <>
            <Head title={`${student.name} · ${t('Fees')}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={student.name}
                    description={[student.name_bn, student.admission_no, [student.class, student.section].filter(Boolean).join(' · '), student.roll && `${t('Roll')} ${student.roll}`].filter(Boolean).join(' · ')}
                    actions={<Button variant="outline" asChild><Link href={studentProfile(student.id)}>{t('Student profile')}</Link></Button>}
                />

                {lastReceipt && (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-300/60 bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200">
                        {t('Payment recorded.')}
                        <Button size="sm" asChild><a href={receipt.url(lastReceipt)} target="_blank" rel="noreferrer"><Printer />{t('Print receipt')}</a></Button>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label={t('Due now')} value={formatMoney(summary.due)} tone={Number(summary.due) > 0 ? 'warning' : 'success'} />
                    <StatCard label={t('Overdue')} value={formatMoney(summary.overdue)} tone={Number(summary.overdue) > 0 ? 'danger' : 'default'} />
                    <StatCard label={t('Paid this session')} value={formatMoney(summary.paid_this_session)} tone="success" />
                </div>

                <div className="grid gap-6 lg:grid-cols-5">
                    <div className="grid content-start gap-6 lg:col-span-3">
                        <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                            <header className="flex items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                                <h2 className="font-medium">{t('Invoices')}</h2>
                                {can.manage && <OneOffInvoice studentId={student.id} categories={props.options.categories} today={props.today} />}
                            </header>
                            <ul className="divide-y divide-border/70 text-sm">
                                {invoices.length === 0 && <li className="px-4 py-6 text-center text-muted-foreground">{t('No invoices yet')}</li>}
                                {invoices.map((i) => (
                                    <li key={i.id}>
                                        <Link href={invoiceRoute(i.id)} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 hover:bg-muted/30">
                                            <div>
                                                <p className="font-medium">{i.billing_period ? periodLabel(i.billing_period, locale) : t('One-off invoice')} <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">{i.invoice_no}</span></p>
                                                <p className="text-xs text-muted-foreground">{t('Due')} {formatDate(i.due_date)} · {t('Total')} {formatMoney(i.total)}</p>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {Number(i.balance) > 0 && <span className="font-medium tabular-nums">{formatMoney(i.balance)}</span>}
                                                <InvoiceStatus invoice={i} />
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                            <h2 className="border-b border-border/70 px-4 py-3 font-medium">{t('Payments')}</h2>
                            <ul className="divide-y divide-border/70 text-sm">
                                {payments.length === 0 && <li className="px-4 py-6 text-center text-muted-foreground">{t('No payments yet')}</li>}
                                {payments.map((p) => (
                                    <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                                        <div>
                                            {p.status === 'successful' ? <a href={receipt.url(p.id)} target="_blank" rel="noreferrer" className="font-mono text-xs text-primary hover:underline">{p.receipt_no}</a> : <span className="font-mono text-xs text-muted-foreground">{t('payment_' + p.status)}</span>}
                                            <p className="text-xs text-muted-foreground">{formatDateTime(p.received_at)} · {t('method_' + p.method)}{p.reference ? ` · ${p.reference}` : ''}{p.received_by ? ` · ${p.received_by}` : ''}</p>
                                            {p.reversal && <p className="text-xs text-muted-foreground">{p.reversal.reason}</p>}
                                        </div>
                                        <div className="flex items-center gap-3"><PaymentStatus payment={p} /><span className={cn('font-medium tabular-nums', p.reversal && 'line-through')}>{formatMoney(p.amount)}</span></div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>

                    <div className="grid content-start gap-6 lg:col-span-2">
                        {can.collect && <CollectForm {...props} />}

                        <FormSection title={t('Extra fees and overrides')} description={t('Opt this student into fees such as transport, or change what they pay for a class fee (0 waives it).')}>
                            <ul className="mb-3 divide-y divide-border/70 text-sm">
                                {assignments.length === 0 && <li className="py-2 text-muted-foreground">{t('None')}</li>}
                                {assignments.map((a) => (
                                    <li key={a.id} className={cn('flex items-center justify-between gap-2 py-2', !a.is_active && 'text-muted-foreground line-through')}>
                                        <span>{a.structure}<span className="ml-1 text-xs text-muted-foreground">{a.custom_amount !== null ? formatMoney(a.custom_amount) : formatMoney(a.default_amount)}</span></span>
                                        {can.manage && <Button size="sm" variant="ghost" onClick={() => router.post(StudentFeeController.toggleAssignment.url(a.id), {}, { preserveScroll: true })}>{a.is_active ? t('Stop') : t('Resume')}</Button>}
                                    </li>
                                ))}
                            </ul>
                            {can.manage && <AssignmentForm studentId={student.id} structures={props.options.structures} />}
                        </FormSection>

                        <FormSection title={t('Discounts and scholarships')} description={t('Applied to invoices generated from now on.')}>
                            <ul className="mb-3 divide-y divide-border/70 text-sm">
                                {discounts.length === 0 && <li className="py-2 text-muted-foreground">{t('None')}</li>}
                                {discounts.map((d) => (
                                    <li key={d.id} className={cn('flex items-center justify-between gap-2 py-2', !d.is_active && 'text-muted-foreground line-through')}>
                                        <span>{d.name} · {d.kind === 'percent' ? `${Number(d.value)}%` : formatMoney(d.value)} · {d.category ?? t('All fees')}</span>
                                        {can.manage && <Button size="sm" variant="ghost" onClick={() => router.post(StudentFeeController.toggleDiscount.url(d.id), {}, { preserveScroll: true })}>{d.is_active ? t('Stop') : t('Resume')}</Button>}
                                    </li>
                                ))}
                            </ul>
                            {can.manage && <DiscountForm studentId={student.id} categories={props.options.categories} />}
                        </FormSection>

                        {payments.some((p) => p.status === 'successful' && !p.reversal) && <ReverseHint />}
                    </div>
                </div>
            </div>
        </>
    );
}

/** A random key per payment attempt, so a double-clicked or resent form records one payment. */
function newKey(): string {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes); // works on plain-http school hosts too, unlike randomUUID()

    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function CollectForm({ student, summary, openInvoices, today }: Props) {
    const t = useT();
    const [choose, setChoose] = useState(false);
    const form = useForm({
        amount: summary.due,
        method: 'cash',
        reference: '',
        received_on: today,
        notes: '',
        idempotency_key: newKey(),
        allocations: {} as Record<number, string>,
    });
    const errors = form.errors as Record<string, string>;
    const due = Number(summary.due);

    if (due <= 0) {
        return (
            <FormSection title={t('Collect payment')}>
                <p className="text-sm text-muted-foreground">{t('Nothing is due.')}</p>
            </FormSection>
        );
    }

    const allocated = Object.values(form.data.allocations).reduce((s, v) => s + (Number(v) || 0), 0);

    const submit = () => {
        form.transform((data) => ({ ...data, amount: choose ? allocated.toFixed(2) : data.amount, allocations: choose ? data.allocations : undefined }));
        form.post(PaymentController.store.url(student.id), {
            preserveScroll: true,
            // Same key while retrying after an error; a fresh one for the next payment.
            onSuccess: () => form.setData({ amount: '', method: 'cash', reference: '', received_on: today, notes: '', idempotency_key: newKey(), allocations: {} }),
        });
    };

    return (
        <FormSection title={t('Collect payment')} description={t(':count open invoices', { count: openInvoices.length })}>
            <div className="grid gap-3">
                <div className="flex gap-1 rounded-lg bg-muted p-1 text-sm" role="tablist">
                    <button type="button" role="tab" aria-selected={!choose} className={cn('flex-1 rounded-md px-2 py-1', !choose && 'bg-background shadow-xs')} onClick={() => setChoose(false)}>{t('Oldest due first')}</button>
                    <button type="button" role="tab" aria-selected={choose} className={cn('flex-1 rounded-md px-2 py-1', choose && 'bg-background shadow-xs')} onClick={() => setChoose(true)}>{t('Choose invoices')}</button>
                </div>

                {choose ? (
                    <ul className="grid gap-2 text-sm">
                        {openInvoices.map((i) => (
                            <li key={i.id} className="flex items-center justify-between gap-2">
                                <span>{i.invoice_no}<span className="block text-xs text-muted-foreground">{t('Due')} {formatDate(i.due_date)} · {formatMoney(i.balance)}</span></span>
                                <Input className="w-28 text-right tabular-nums" inputMode="decimal" placeholder="0" value={form.data.allocations[i.id] ?? ''} onChange={(e) => form.setData('allocations', { ...form.data.allocations, [i.id]: e.target.value })} aria-label={t('Amount for :invoice', { invoice: i.invoice_no })} />
                            </li>
                        ))}
                        <li className="flex justify-between border-t pt-2 font-medium"><span>{t('Total')}</span><span className="tabular-nums">{formatMoney(allocated)}</span></li>
                        <InputError message={errors.allocations ?? Object.entries(errors).find(([k]) => k.startsWith('allocations.'))?.[1]} />
                    </ul>
                ) : (
                    <Field label={t('Amount (BDT)')} name="amount" required error={errors.amount} hint={t('Due: :due', { due: formatMoney(summary.due) })}>
                        <Input id="amount" inputMode="decimal" className="text-lg font-semibold tabular-nums" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                    </Field>
                )}

                <div className="grid grid-cols-2 gap-3">
                    <Field label={t('Method')} name="method" error={errors.method}>
                        <select id="method" className={selectClass} value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                            {['cash', 'mobile_banking', 'bank', 'cheque'].map((m) => <option key={m} value={m}>{t('method_' + m)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('Received on')} name="received_on" error={errors.received_on}>
                        <Input id="received_on" type="date" max={today} value={form.data.received_on} onChange={(e) => form.setData('received_on', e.target.value)} />
                    </Field>
                </div>
                {form.data.method !== 'cash' && (
                    <Field label={form.data.method === 'mobile_banking' ? t('Transaction ID') : t('Reference')} name="reference" required error={errors.reference}>
                        <Input id="reference" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                    </Field>
                )}
                <Field label={t('Note (optional)')} name="notes" error={errors.notes}><Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                <Button size="lg" onClick={submit} disabled={form.processing || (choose && allocated <= 0)}>{form.processing && <Spinner />}<Receipt />{t('Record payment')}</Button>
            </div>
        </FormSection>
    );
}

function AssignmentForm({ studentId, structures }: { studentId: number; structures: Props['options']['structures'] }) {
    const t = useT();
    const form = useForm({ fee_structure_id: '', custom_amount: '', note: '' });
    const selected = structures.find((s) => String(s.id) === form.data.fee_structure_id);

    return (
        <div className="grid gap-2">
            <select className={selectClass} value={form.data.fee_structure_id} onChange={(e) => form.setData('fee_structure_id', e.target.value)} aria-label={t('Fee plan')}>
                <option value="">{t('Choose a fee plan…')}</option>
                {structures.map((s) => <option key={s.id} value={s.id}>{s.name} · {formatMoney(s.amount)}{s.applies_to === 'assigned' ? ` (${t('opt-in')})` : ''}</option>)}
            </select>
            {selected && (
                <Input inputMode="decimal" placeholder={selected.applies_to === 'assigned' ? t('Amount (leave empty for :amount)', { amount: selected.amount }) : t('Amount this student pays')} value={form.data.custom_amount} onChange={(e) => form.setData('custom_amount', e.target.value)} aria-label={t('Amount')} />
            )}
            <InputError message={form.errors.custom_amount ?? form.errors.fee_structure_id} />
            <Button size="sm" variant="outline" disabled={!selected || form.processing} onClick={() => form.post(StudentFeeController.storeAssignment.url(studentId), { preserveScroll: true, onSuccess: () => form.reset() })}><Plus />{t('Add')}</Button>
        </div>
    );
}

function DiscountForm({ studentId, categories }: { studentId: number; categories: { id: number; name: string }[] }) {
    const t = useT();
    const form = useForm({ name: '', kind: 'percent', value: '', fee_category_id: '' });

    return (
        <div className="grid gap-2">
            <Input placeholder={t('e.g. Sibling discount')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-label={t('Name')} />
            <div className="grid grid-cols-3 gap-2">
                <select className={selectClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)} aria-label={t('Type')}><option value="percent">%</option><option value="fixed">৳</option></select>
                <Input className="col-span-2" inputMode="decimal" placeholder={t('Value')} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} aria-label={t('Value')} />
            </div>
            <select className={selectClass} value={form.data.fee_category_id} onChange={(e) => form.setData('fee_category_id', e.target.value)} aria-label={t('Fee head')}>
                <option value="">{t('All fees')}</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <InputError message={form.errors.value ?? form.errors.name} />
            <Button size="sm" variant="outline" disabled={form.processing} onClick={() => form.post(StudentFeeController.storeDiscount.url(studentId), { preserveScroll: true, onSuccess: () => form.reset() })}><Plus />{t('Add')}</Button>
        </div>
    );
}

function OneOffInvoice({ studentId, categories, today }: { studentId: number; categories: { id: number; name: string }[]; today: string }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ due_date: today, notes: '', items: [{ fee_category_id: '', description: '', amount: '' }] });
    const errors = form.errors as Record<string, string>;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button size="sm" variant="outline"><Plus />{t('One-off charge')}</Button></DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{t('One-off charge')}</DialogTitle>
                <DialogDescription>{t('A separate invoice, e.g. for an ID card or a lost library book.')}</DialogDescription>
                {form.data.items.map((it, i) => (
                    <div key={i} className="grid grid-cols-[1fr_7rem] gap-2">
                        <Input placeholder={t('Description')} value={it.description} onChange={(e) => form.setData('items', form.data.items.map((x, n) => (n === i ? { ...x, description: e.target.value } : x)))} aria-label={t('Description')} />
                        <Input inputMode="decimal" placeholder={t('Amount')} value={it.amount} onChange={(e) => form.setData('items', form.data.items.map((x, n) => (n === i ? { ...x, amount: e.target.value } : x)))} aria-label={t('Amount')} />
                        <select className={cn(selectClass, 'col-span-2')} value={it.fee_category_id} onChange={(e) => form.setData('items', form.data.items.map((x, n) => (n === i ? { ...x, fee_category_id: e.target.value } : x)))} aria-label={t('Fee head')}>
                            <option value="">{t('Fee head (optional)')}</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                ))}
                <Button size="sm" variant="ghost" className="justify-self-start" onClick={() => form.setData('items', [...form.data.items, { fee_category_id: '', description: '', amount: '' }])}><Plus />{t('Add line')}</Button>
                <Field label={t('Due date')} name="due_date" error={errors.due_date}><Input id="due_date" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} /></Field>
                <InputError message={errors.items ?? Object.entries(errors).find(([k]) => k.startsWith('items.'))?.[1]} />
                <Button disabled={form.processing} onClick={() => form.post(InvoiceController.store.url(studentId))}>{form.processing && <Spinner />}{t('Create invoice')}</Button>
            </DialogContent>
        </Dialog>
    );
}

function ReverseHint() {
    const t = useT();

    return (
        <p className="flex gap-2 text-xs text-muted-foreground">
            <RotateCcw className="size-4 shrink-0" />
            {t('To correct a payment, reverse or refund it from the Payments page. Payments are never edited or deleted.')}
        </p>
    );
}

StudentFees.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }] };

