import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDateTime, useT } from '@/lib/i18n';
import type { Payment } from './shared';

type Props = {
    school: { name: string; name_bn: string | null; address: string | null; phone: string | null; email: string | null };
    payment: Payment & {
        student_bn: string | null; class: string | null; section: string | null; roll: string | null; notes: string | null;
        allocations: { invoice_no: string; period: string | null; amount: string; items: { description: string; line_total: string }[]; balance_now: string | null }[];
    };
    note: string | null;
};

const taka = (v: string) => `৳ ${Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const methodLabel: Record<string, [string, string]> = {
    cash: ['Cash', 'নগদ'], bank: ['Bank', 'ব্যাংক'], cheque: ['Cheque', 'চেক'], mobile_banking: ['Mobile banking', 'মোবাইল ব্যাংকিং'], online: ['Online', 'অনলাইন'],
};

/** Bilingual A5 money receipt, printed from the browser (correct Bangla shaping). */
export default function ReceiptPrint({ school, payment: p, note }: Props) {
    const t = useT();
    const when = formatDateTime(p.received_at, 'en'); // school timezone

    return (
        <>
            <Head title={`${t('Receipt')} ${p.receipt_no}`} />
            <style>{`@page { size: A5; margin: 10mm; } @media print { .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; width: auto !important; padding: 0 1mm !important; } body { background: white !important; } }`}</style>
            <div className="no-print flex items-center justify-between border-b bg-white px-6 py-3 text-sm">
                <span>{t('Receipt')} {p.receipt_no}</span>
                <Button onClick={() => window.print()}><Printer />{t('Print')}</Button>
            </div>
            <main className="min-h-screen bg-zinc-100 py-6 text-[12px] text-zinc-900 print:bg-white print:py-0">
                <article className="sheet relative mx-auto w-[148mm] max-w-full bg-white p-[10mm] shadow-sm">
                    {p.reversal && (
                        <div aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <span className="-rotate-30 text-5xl font-bold text-red-200/80">{p.reversal.type === 'refund' ? 'REFUNDED · ফেরত' : 'REVERSED · বাতিল'}</span>
                        </div>
                    )}
                    <header className="border-b-2 border-zinc-800 pb-2 text-center">
                        <h1 className="text-lg font-bold">{school.name}</h1>
                        {school.name_bn && <p lang="bn" className="font-semibold">{school.name_bn}</p>}
                        <p className="text-zinc-600">{[school.address, school.phone].filter(Boolean).join(' · ')}</p>
                        <p className="mt-1 inline-block rounded border border-zinc-800 px-2 font-semibold">MONEY RECEIPT · <span lang="bn">টাকা প্রাপ্তির রসিদ</span></p>
                    </header>

                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1">
                        <div><dt className="text-zinc-500">Receipt no. · <span lang="bn">রসিদ নং</span></dt><dd className="font-mono font-semibold">{p.receipt_no}</dd></div>
                        <div><dt className="text-zinc-500">Date · <span lang="bn">তারিখ</span></dt><dd>{when}</dd></div>
                        <div className="col-span-2"><dt className="text-zinc-500">Received from · <span lang="bn">যার নিকট হতে</span></dt><dd className="font-semibold">{p.student}{p.student_bn && <span lang="bn" className="ml-1 font-normal">({p.student_bn})</span>}</dd></div>
                        <div><dt className="text-zinc-500">Admission no. · <span lang="bn">ভর্তি নং</span></dt><dd className="font-mono">{p.admission_no}</dd></div>
                        <div><dt className="text-zinc-500">Class · <span lang="bn">শ্রেণি</span></dt><dd>{[p.class, p.section].filter(Boolean).join(' · ')}{p.roll && ` · Roll ${p.roll}`}</dd></div>
                    </dl>

                    <table className="mt-3 w-full border-collapse">
                        <thead><tr className="bg-zinc-100 text-left"><th className="border border-zinc-400 px-2 py-1">Particulars · <span lang="bn">বিবরণ</span></th><th className="border border-zinc-400 px-2 py-1 text-right">Amount · <span lang="bn">টাকা</span></th></tr></thead>
                        <tbody>
                            {p.allocations.map((a) => (
                                <tr key={a.invoice_no}>
                                    <td className="border border-zinc-400 px-2 py-1">
                                        <span className="font-mono text-[10px] text-zinc-500">{a.invoice_no}</span>
                                        <span className="block">{a.items.map((i) => i.description).join(', ')}</span>
                                        {a.balance_now && Number(a.balance_now) > 0 && <span className="block text-[10px] text-zinc-500">Still due · <span lang="bn">বাকি</span> {taka(a.balance_now)}</span>}
                                    </td>
                                    <td className="border border-zinc-400 px-2 py-1 text-right tabular-nums">{taka(a.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot><tr><td className="border border-zinc-400 px-2 py-1 text-right font-semibold">Total received · <span lang="bn">মোট প্রাপ্ত</span></td><td className="border border-zinc-400 px-2 py-1 text-right text-sm font-bold tabular-nums">{taka(p.amount)}</td></tr></tfoot>
                    </table>

                    <p className="mt-2">Paid by · <span lang="bn">পরিশোধের মাধ্যম</span>: <b>{methodLabel[p.method]?.[0]} · <span lang="bn">{methodLabel[p.method]?.[1]}</span></b>{p.reference && ` · ${p.reference}`}</p>
                    {p.notes && <p className="text-zinc-600">{p.notes}</p>}
                    {note && <p className="mt-2 text-[10px] text-zinc-500">{note}</p>}

                    <footer className="mt-10 flex justify-between gap-6 text-center">
                        <div className="w-40 border-t border-zinc-500 pt-1">Received by · <span lang="bn">গ্রহণকারী</span><p className="text-[10px] text-zinc-500">{p.received_by ?? p.gateway}</p></div>
                        <div className="w-40 border-t border-zinc-500 pt-1">Seal · <span lang="bn">সিল</span></div>
                    </footer>
                </article>
            </main>
        </>
    );
}
