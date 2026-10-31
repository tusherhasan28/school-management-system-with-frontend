<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\FeePaymentReversal;
use App\Modules\Imports\Services\StudentImportService;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Collections (money in, by day/method/collector, net of reversals and
 * refunds) and dues (open balances per class, by how late they are).
 */
class FinanceReportController extends Controller
{
    /** Days past the due date. */
    private const AGING = ['not_due' => 0, 'd1_30' => 30, 'd31_60' => 60, 'd61_90' => 90, 'd90_plus' => null];

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('fees.reports'), 403);
        [$from, $to] = $this->range($request);
        $payments = $this->payments($from, $to);
        $reversals = $this->reversals($from, $to);
        $tz = $this->tenant->require()->timezone;

        $byDay = $payments->groupBy(fn ($p) => $p->received_at->setTimezone($tz)->toDateString())->map(fn ($g) => Money::format(Money::sum($g->pluck('amount'))))->sortKeys();

        return Inertia::render('app/fees/reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'collections' => [
                'gross' => Money::format(Money::sum($payments->pluck('amount'))),
                'reversed' => Money::format(Money::sum($reversals->pluck('amount'))),
                'net' => Money::format(Money::sum($payments->pluck('amount')) - Money::sum($reversals->pluck('amount'))),
                'count' => $payments->count(),
                'by_method' => $payments->groupBy('method')->map(fn ($g, $m) => ['method' => $m, 'count' => $g->count(), 'amount' => Money::format(Money::sum($g->pluck('amount')))])->values(),
                'by_collector' => $payments->groupBy(fn ($p) => $p->receiver?->name ?? ($p->gateway ? 'online:'.$p->gateway : '—'))->map(fn ($g, $n) => ['name' => $n, 'count' => $g->count(), 'amount' => Money::format(Money::sum($g->pluck('amount')))])->values(),
                'by_day' => $byDay->map(fn ($amount, $day) => ['day' => $day, 'amount' => $amount])->values(),
            ],
            'dues' => $this->dues(),
        ]);
    }

    public function export(Request $request, string $report): BinaryFileResponse
    {
        abort_unless($request->user()->can('fees.reports'), 403);
        abort_unless(in_array($report, ['collections', 'dues'], true), 404);
        [$from, $to] = $this->range($request);
        $tz = $this->tenant->require()->timezone;
        $safe = fn ($v) => is_string($v) ? StudentImportService::safeCell($v) : $v;

        $path = tempnam(sys_get_temp_dir(), 'fin').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        if ($report === 'collections') {
            $writer->addRow(Row::fromValues(['Receipt', 'Date', 'Student', 'Admission no.', 'Method', 'Reference', 'Amount (BDT)', 'Received by', 'Reversed']));

            foreach ($this->payments($from, $to) as $p) {
                $writer->addRow(Row::fromValues(array_map($safe, [
                    $p->receipt_no, $p->received_at->setTimezone($tz)->format('Y-m-d H:i'), $p->student?->full_name, $p->student?->admission_no,
                    $p->method, (string) $p->reference, (float) $p->amount, $p->receiver?->name ?? (string) $p->gateway, $p->reversal ? $p->reversal->type : '',
                ])));
            }
        } else {
            $writer->addRow(Row::fromValues(['Invoice', 'Student', 'Admission no.', 'Class', 'Section', 'Period', 'Due date', 'Days late', 'Total', 'Paid', 'Balance (BDT)']));
            $today = CarbonImmutable::now($tz)->startOfDay();

            foreach ($this->openInvoices() as $i) {
                $late = max(0, (int) CarbonImmutable::parse($i->due_date->toDateString())->diffInDays($today, false));
                $writer->addRow(Row::fromValues(array_map($safe, [
                    $i->invoice_no, $i->student?->full_name, $i->student?->admission_no, $i->schoolClass?->name, $i->section?->name, (string) $i->billing_period,
                    $i->due_date->toDateString(), $late, (float) $i->total, (float) $i->paid_total, (float) Money::format($i->balance()),
                ])));
            }
        }

        $writer->close();

        return response()->download($path, "{$report}-{$from->toDateString()}-{$to->toDateString()}.xlsx")->deleteFileAfterSend();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $tz = $this->tenant->require()->timezone;
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return [
            CarbonImmutable::parse($f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay(),
            CarbonImmutable::parse($f['to'] ?? CarbonImmutable::now($tz)->toDateString(), $tz)->endOfDay(),
        ];
    }

    /** @return Collection<int, FeePayment> */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return FeePayment::query()->with(['student:id,school_id,full_name,admission_no', 'receiver:id,school_id,name', 'reversal'])
            ->where('status', 'successful')->whereBetween('received_at', [$from->utc(), $to->utc()])->orderBy('received_at')->get();
    }

    /** @return Collection<int, FeePaymentReversal> */
    private function reversals(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return FeePaymentReversal::query()->whereBetween('created_at', [$from->utc(), $to->utc()])->get();
    }

    /** @return Collection<int, FeeInvoice> */
    private function openInvoices(): Collection
    {
        return FeeInvoice::query()->open()->with(['student:id,school_id,full_name,admission_no', 'schoolClass:id,school_id,name,level_order', 'section:id,school_id,name'])
            ->orderBy('due_date')->get();
    }

    /** @return array<string, mixed> */
    private function dues(): array
    {
        $today = CarbonImmutable::now($this->tenant->require()->timezone)->startOfDay();
        $classes = SchoolClass::query()->orderBy('level_order')->get(['id', 'name', 'school_id'])->keyBy('id');
        $rows = [];
        $totals = array_fill_keys(array_keys(self::AGING), 0);

        foreach ($this->openInvoices() as $i) {
            $late = (int) CarbonImmutable::parse($i->due_date->toDateString())->diffInDays($today, false);
            $bucket = match (true) {
                $late <= 0 => 'not_due',
                $late <= 30 => 'd1_30',
                $late <= 60 => 'd31_60',
                $late <= 90 => 'd61_90',
                default => 'd90_plus',
            };
            $classId = $i->class_id ?? 0;
            $rows[$classId] ??= ['class' => $classes->get($classId)?->name ?? '—', 'order' => $classes->get($classId)?->level_order ?? 999, 'students' => [], 'total' => 0] + array_fill_keys(array_keys(self::AGING), 0);
            $rows[$classId][$bucket] += $i->balance();
            $rows[$classId]['total'] += $i->balance();
            $rows[$classId]['students'][$i->student_id] = true;
            $totals[$bucket] += $i->balance();
        }

        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

        return [
            'rows' => array_map(fn ($r) => ['class' => $r['class'], 'students' => count($r['students']), 'total' => Money::format($r['total'])]
                + array_map(fn ($v) => Money::format($v), array_intersect_key($r, self::AGING)), $rows),
            'totals' => array_map(fn ($v) => Money::format($v), $totals) + ['total' => Money::format(array_sum($totals))],
        ];
    }
}
