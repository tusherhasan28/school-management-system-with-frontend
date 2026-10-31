import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDate, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Part = { name: string; name_bn: string | null; full: string; marks: string | null; passed: boolean };
type Subject = { name: string; name_bn: string | null; counted_as: string; status: string; full: string; obtained: string; grade: string; gp: string; passed: boolean; components: Part[] };
type Card = {
    student: { id: number; name: string; name_bn: string | null; admission_no: string; roll: string | null; section: string | null; guardian: string | null };
    result: {
        full_marks: string; obtained_marks: string; percentage: string; gpa: string | null; gpa_without_optional: string | null; letter_grade: string | null;
        failed_subjects: number; class_position: number | null; section_position: number | null; status: string;
    };
    subjects: Subject[];
    attendance: { present: number; late: number; half_day: number; absent: number; leave: number; marked: number; rate: number | null } | null;
    class_teacher: string | null;
};

type Props = {
    school: { name: string; name_bn: string | null; address: string | null; phone: string | null; email: string | null };
    exam: { name: string; name_bn: string | null; published: boolean; session: string | null; starts_on: string | null; ends_on: string | null; version: number | null };
    class: { name: string; name_bn: string | null };
    bands: { grade: string; range: string; gp: string; remarks: string | null; remarks_bn: string | null }[];
    showGpa: boolean;
    cards: Card[];
};

/** Both languages on every label: report cards go to every family. */
function L({ en, bn, className }: { en: string; bn: string; className?: string }) {
    return (
        <span className={cn('inline-flex flex-col leading-tight', className)}>
            <span>{en}</span>
            <span lang="bn" className="text-[0.8em] text-zinc-500">{bn}</span>
        </span>
    );
}

const n = (v: string | null | undefined) => (v === null || v === undefined ? '—' : String(Number(v)));
const resultBn: Record<string, string> = { pass: 'উত্তীর্ণ', fail: 'অনুত্তীর্ণ', incomplete: 'অসম্পূর্ণ' };
const resultEn: Record<string, string> = { pass: 'Passed', fail: 'Not passed', incomplete: 'Incomplete' };

export default function ReportCards({ school, exam, class: cls, bands, showGpa, cards }: Props) {
    const t = useT();

    return (
        <>
            <Head title={`${exam.name} · ${t('Report cards')}`} />
            <style>{`
                @page { size: A4; margin: 12mm; }
                @media print {
                    .no-print { display: none !important; }
                    body { background: white !important; }
                    .report-card { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 1mm !important; width: auto !important; overflow: visible !important; }
                    .report-card + .report-card { break-before: page; }
                }
            `}</style>

            <div className="no-print sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 border-b bg-white/95 px-6 py-3 text-sm backdrop-blur">
                <span>{t(':count report cards', { count: cards.length })} · {cls.name}{!exam.published && ` · ${t('Draft — not published')}`}</span>
                <Button onClick={() => window.print()}><Printer />{t('Print')}</Button>
            </div>

            <main className="min-h-screen bg-zinc-100 py-6 text-zinc-900 print:bg-white print:py-0">
                {cards.map((c) => (
                    <article key={c.student.id} className="report-card relative mx-auto mb-6 w-[210mm] max-w-full overflow-hidden bg-white p-[12mm] text-[12px] shadow-sm">
                        {!exam.published && (
                            <div aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center">
                                <span className="-rotate-30 text-[64px] font-bold tracking-widest text-zinc-200/70 select-none">DRAFT · খসড়া</span>
                            </div>
                        )}

                        <header className="relative border-b-2 border-zinc-800 pb-3 text-center">
                            <h1 className="text-xl font-bold">{school.name}</h1>
                            {school.name_bn && <p lang="bn" className="text-lg font-semibold">{school.name_bn}</p>}
                            <p className="text-zinc-600">{[school.address, school.phone, school.email].filter(Boolean).join(' · ')}</p>
                            <p className="mt-2 inline-block rounded border border-zinc-800 px-3 py-0.5 font-semibold tracking-wide">
                                PROGRESS REPORT · <span lang="bn">অগ্রগতি প্রতিবেদন</span>
                            </p>
                            <p className="mt-1 font-medium">
                                {exam.name}{exam.name_bn && <> · <span lang="bn">{exam.name_bn}</span></>}{exam.session && ` · ${exam.session}`}
                            </p>
                        </header>

                        <dl className="relative mt-3 grid grid-cols-3 gap-x-4 gap-y-2">
                            <div className="col-span-2"><dt><L en="Student" bn="শিক্ষার্থী" className="text-zinc-500" /></dt><dd className="font-semibold">{c.student.name}{c.student.name_bn && <span lang="bn" className="ml-2 font-normal">({c.student.name_bn})</span>}</dd></div>
                            <div><dt><L en="Admission no." bn="ভর্তি নম্বর" className="text-zinc-500" /></dt><dd className="font-mono">{c.student.admission_no}</dd></div>
                            <div><dt><L en="Class" bn="শ্রেণি" className="text-zinc-500" /></dt><dd className="font-medium">{cls.name}{cls.name_bn && <span lang="bn"> · {cls.name_bn}</span>}</dd></div>
                            <div><dt><L en="Section · Roll" bn="শাখা · রোল" className="text-zinc-500" /></dt><dd className="font-medium">{c.student.section ?? '—'} · {c.student.roll ?? '—'}</dd></div>
                            <div><dt><L en="Guardian" bn="অভিভাবক" className="text-zinc-500" /></dt><dd>{c.student.guardian ?? '—'}</dd></div>
                        </dl>

                        <table className="relative mt-4 w-full border-collapse">
                            <thead>
                                <tr className="bg-zinc-100 text-left">
                                    <th className="border border-zinc-400 px-2 py-1"><L en="Subject" bn="বিষয়" /></th>
                                    <th className="border border-zinc-400 px-2 py-1"><L en="Parts" bn="অংশভিত্তিক নম্বর" /></th>
                                    <th className="border border-zinc-400 px-2 py-1 text-center"><L en="Full" bn="পূর্ণ" /></th>
                                    <th className="border border-zinc-400 px-2 py-1 text-center"><L en="Obtained" bn="প্রাপ্ত" /></th>
                                    <th className="border border-zinc-400 px-2 py-1 text-center"><L en="Grade" bn="গ্রেড" /></th>
                                    {showGpa && <th className="border border-zinc-400 px-2 py-1 text-center"><L en="GP" bn="জিপি" /></th>}
                                </tr>
                            </thead>
                            <tbody>
                                {c.subjects.map((s, i) => (
                                    <tr key={i} className={cn(!s.passed && s.counted_as !== 'non_gpa' && 'text-red-700')}>
                                        <td className="border border-zinc-400 px-2 py-1">
                                            <span className="font-medium">{s.name}</span>
                                            {s.name_bn && <span lang="bn" className="ml-1 text-zinc-500">· {s.name_bn}</span>}
                                            {s.counted_as === 'optional' && <span className="ml-1 text-[10px] text-zinc-500">(4th · ঐচ্ছিক)</span>}
                                            {s.counted_as === 'non_gpa' && <span className="ml-1 text-[10px] text-zinc-500">(non-GPA)</span>}
                                        </td>
                                        <td className="border border-zinc-400 px-2 py-1 text-zinc-600">
                                            {s.status === 'absent' ? 'Absent · অনুপস্থিত' : s.components.map((p) => `${p.name} ${n(p.marks)}/${n(p.full)}`).join(' · ')}
                                        </td>
                                        <td className="border border-zinc-400 px-2 py-1 text-center tabular-nums">{n(s.full)}</td>
                                        <td className="border border-zinc-400 px-2 py-1 text-center font-medium tabular-nums">{s.status === 'absent' ? '—' : n(s.obtained)}</td>
                                        <td className="border border-zinc-400 px-2 py-1 text-center font-semibold">{s.grade}</td>
                                        {showGpa && <td className="border border-zinc-400 px-2 py-1 text-center tabular-nums">{s.gp}</td>}
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <section className="relative mt-4 grid grid-cols-4 gap-2 text-center">
                            <div className="rounded border border-zinc-400 p-2"><L en="Total marks" bn="মোট নম্বর" className="text-zinc-500" /><p className="text-base font-bold tabular-nums">{n(c.result.obtained_marks)} / {n(c.result.full_marks)}</p><p className="text-zinc-500 tabular-nums">{c.result.percentage}%</p></div>
                            {showGpa ? (
                                <div className="rounded border border-zinc-400 p-2"><L en="GPA" bn="জিপিএ" className="text-zinc-500" /><p className="text-base font-bold tabular-nums">{c.result.gpa ?? '—'}</p>{c.result.gpa_without_optional && c.result.gpa_without_optional !== c.result.gpa && <p className="text-[10px] text-zinc-500">without 4th · ৪র্থ বিষয় ছাড়া {c.result.gpa_without_optional}</p>}</div>
                            ) : (
                                <div className="rounded border border-zinc-400 p-2"><L en="Percentage" bn="শতকরা" className="text-zinc-500" /><p className="text-base font-bold tabular-nums">{c.result.percentage}%</p></div>
                            )}
                            <div className="rounded border border-zinc-400 p-2"><L en="Grade · Result" bn="গ্রেড · ফলাফল" className="text-zinc-500" /><p className="text-base font-bold">{c.result.letter_grade ?? '—'}</p><p className={cn('font-medium', c.result.status === 'pass' ? 'text-emerald-700' : 'text-red-700')}>{resultEn[c.result.status]} · <span lang="bn">{resultBn[c.result.status]}</span></p></div>
                            <div className="rounded border border-zinc-400 p-2"><L en="Merit position" bn="মেধাক্রম" className="text-zinc-500" /><p className="text-base font-bold tabular-nums">{c.result.section_position ?? '—'}</p><p className="text-[10px] text-zinc-500">Section · শাখা{c.result.class_position ? ` · Class · শ্রেণি ${c.result.class_position}` : ''}</p></div>
                        </section>

                        <section className="relative mt-3 grid grid-cols-2 gap-3">
                            <div className="rounded border border-zinc-400 p-2">
                                <p className="mb-1 font-medium"><L en="Attendance" bn="উপস্থিতি" /></p>
                                {c.attendance ? (
                                    <p className="tabular-nums">
                                        Present · উপস্থিত {c.attendance.present + c.attendance.late} / {c.attendance.marked - c.attendance.leave}
                                        {c.attendance.rate !== null && ` (${c.attendance.rate}%)`} · Absent · অনুপস্থিত {c.attendance.absent}
                                    </p>
                                ) : <p className="text-zinc-500">—</p>}
                            </div>
                            <div className="rounded border border-zinc-400 p-2">
                                <p className="mb-1 font-medium"><L en="Grading" bn="গ্রেডিং" /></p>
                                <p className="text-[10px] leading-snug text-zinc-600">
                                    {bands.map((b) => `${b.grade} ${b.range}%${showGpa ? ` (${Number(b.gp)})` : ''}`).join(' · ')}
                                </p>
                            </div>
                        </section>

                        <footer className="relative mt-12 grid grid-cols-3 gap-6 text-center">
                            {[
                                ['Class teacher', 'শ্রেণি শিক্ষক', c.class_teacher],
                                ['Principal', 'প্রধান শিক্ষক', null],
                                ['Guardian', 'অভিভাবক', null],
                            ].map(([en, bn, name]) => (
                                <div key={en as string} className="border-t border-zinc-500 pt-1">
                                    <L en={en as string} bn={bn as string} />
                                    {name && <p className="text-[10px] text-zinc-500">{name}</p>}
                                </div>
                            ))}
                        </footer>
                        <p className="relative mt-4 text-right text-[9px] text-zinc-400">
                            {exam.ends_on ? formatDate(exam.ends_on, 'en') : ''}{exam.version ? ` · v${exam.version}` : ''}
                        </p>
                    </article>
                ))}
            </main>
        </>
    );
}
