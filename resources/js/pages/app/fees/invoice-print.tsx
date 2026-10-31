import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { Invoice } from './shared';

type Props = {
    school: { name: string; name_bn: string | null; address: string | null; phone: string | null; email: string | null };
    invoice: Invoice & { student_bn: string | null; guardian: string | null; items: { kind: string; description: string; amount: string; discount_amount: string; discount_note: string | null; line_total: string }[] };
    note: string | null;
};

const taka = (v: string) => `৳ ${Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmt = (d: string) => new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${d}T00:00:00Z`));

/** Bilingual A5 fee invoice for families. */
export default function InvoicePrint({ school, invoice: inv, note }: Props) {
    const t = useT();

    return (
        <>
            <Head title={inv.invoice_no} />
            <style>{`@page { size: A5; margin: 10mm; } @media print { .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; width: auto !important; padding: 0 1mm !important; } body { background: white !important; } }`}</style>
            <div className="no-print flex items-center justify-between border-b bg-white px-6 py-3 text-sm">
                <span>{inv.invoice_no}</span>
                <Button onClick={() => window.print()}><Printer />{t('Print')}</Button>
            </div>
            <main className="min-h-screen bg-zinc-100 py-6 text-[12px] text-zinc-900 print:bg-white print:py-0">
                <article className="sheet relative mx-auto w-[148mm] max-w-full bg-white p-[10mm] shadow-sm">
                    {inv.status === 'cancelled' && <div aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center"><span className="-rotate-30 text-5xl font-bold text-red-200/80">CANCELLED · বাতিল</span></div>}
                    {inv.status === 'paid' && <div aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center"><span className="-rotate-30 text-5xl font-bold text-emerald-200/80">PAID · পরিশোধিত</span></div>}
                    <header className="border-b-2 border-zinc-800 pb-2 text-center">
                        <h1 className="text-lg font-bold">{school.name}</h1>
                        {school.name_bn && <p lang="bn" className="font-semibold">{school.name_bn}</p>}
                        <p className="text-zinc-600">{[school.address, school.phone].filter(Boolean).join(' · ')}</p>
                        <p className="mt-1 inline-block rounded border border-zinc-800 px-2 font-semibold">FEE INVOICE · <span lang="bn">বেতন ও ফি বিল</span></p>
                    </header>
                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1">
                        <div><dt className="text-zinc-500">Invoice no. · <span lang="bn">বিল নং</span></dt><dd className="font-mono font-semibold">{inv.invoice_no}</dd></div>
                        <div><dt className="text-zinc-500">Due date · <span lang="bn">শেষ তারিখ</span></dt><dd className="font-semibold">{fmt(inv.due_date)}</dd></div>
                        <div className="col-span-2"><dt className="text-zinc-500">Student · <span lang="bn">শিক্ষার্থী</span></dt><dd className="font-semibold">{inv.student}{inv.student_bn && <span lang="bn" className="ml-1 font-normal">({inv.student_bn})</span>} · <span className="font-mono font-normal">{inv.admission_no}</span></dd></div>
                        <div><dt className="text-zinc-500">Class · <span lang="bn">শ্রেণি</span></dt><dd>{[inv.class, inv.section].filter(Boolean).join(' · ')}</dd></div>
                        <div><dt className="text-zinc-500">Guardian · <span lang="bn">অভিভাবক</span></dt><dd>{inv.guardian ?? '—'}</dd></div>
                    </dl>
                    <table className="mt-3 w-full border-collapse">
                        <thead><tr className="bg-zinc-100 text-left"><th className="border border-zinc-400 px-2 py-1">Particulars · <span lang="bn">বিবরণ</span></th><th className="border border-zinc-400 px-2 py-1 text-right">Amount · <span lang="bn">টাকা</span></th></tr></thead>
                        <tbody>
                            {inv.items.map((i, n) => (
                                <tr key={n}>
                                    <td className="border border-zinc-400 px-2 py-1">{i.description}{Number(i.discount_amount) > 0 && <span className="block text-[10px] text-zinc-500">{i.discount_note ?? 'Discount'}: −{taka(i.discount_amount)}</span>}</td>
                                    <td className="border border-zinc-400 px-2 py-1 text-right tabular-nums">{taka(i.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr><td className="border border-zinc-400 px-2 py-1 text-right">Total · <span lang="bn">মোট</span></td><td className="border border-zinc-400 px-2 py-1 text-right tabular-nums">{taka(inv.total)}</td></tr>
                            <tr><td className="border border-zinc-400 px-2 py-1 text-right">Paid · <span lang="bn">পরিশোধিত</span></td><td className="border border-zinc-400 px-2 py-1 text-right tabular-nums">{taka(inv.paid_total)}</td></tr>
                            <tr><td className="border border-zinc-400 px-2 py-1 text-right font-semibold">Amount due · <span lang="bn">প্রদেয়</span></td><td className="border border-zinc-400 px-2 py-1 text-right text-sm font-bold tabular-nums">{taka(inv.balance)}</td></tr>
                        </tfoot>
                    </table>
                    {note && <p className="mt-3 text-[10px] text-zinc-500">{note}</p>}
                </article>
            </main>
        </>
    );
}
