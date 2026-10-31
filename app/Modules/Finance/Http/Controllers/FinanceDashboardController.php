<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Services\FinancePresenter;
use App\Modules\People\Models\Student;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceDashboardController extends Controller
{
    public function __construct(private readonly FinancePresenter $present, private readonly TenantContext $tenant) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('fees.view') || $user->can('fees.collect'), 403);
        $tz = $this->tenant->require()->timezone;
        $now = CarbonImmutable::now($tz);
        $live = fn () => FeePayment::query()->where('status', 'successful')->whereDoesntHave('reversal');
        $collected = fn (CarbonImmutable $from) => Money::format(Money::sum($live()->where('received_at', '>=', $from->utc())->pluck('amount')));

        $open = FeeInvoice::query()->open();
        // Balances summed in SQL in paisa (exact integers), not row by row in PHP.
        $balance = fn ($q) => (int) $q->sum(\Illuminate\Support\Facades\DB::raw('ROUND(total * 100) - ROUND(paid_total * 100)'));
        $overdue = (clone $open)->where('due_date', '<', $now->toDateString());
        $term = trim((string) $request->query('q', ''));

        return Inertia::render('app/fees/dashboard', [
            'stats' => [
                'today' => $collected($now->startOfDay()),
                'month' => $collected($now->startOfMonth()),
                'outstanding' => Money::format($balance(clone $open)),
                'overdue' => Money::format($balance(clone $overdue)),
                'overdue_count' => (clone $overdue)->count(),
                'pending_online' => FeePayment::query()->where('status', 'pending')->whereNotNull('gateway')->count(),
            ],
            'recent' => $live()->with(['student:id,school_id,full_name,admission_no', 'receiver:id,school_id,name'])->orderByDesc('received_at')->limit(8)->get()->map(fn ($p) => $this->present->payment($p)),
            'q' => $term,
            'matches' => $term === '' ? [] : Student::query()
                ->where(fn ($q) => $q->where('full_name', 'like', "%{$term}%")->orWhere('name_bn', 'like', "%{$term}%")->orWhere('admission_no', 'like', "%{$term}%"))
                ->limit(10)->get(['id', 'full_name', 'name_bn', 'admission_no', 'status']),
            'can' => ['manage' => $user->can('fees.manage'), 'reports' => $user->can('fees.reports')],
        ]);
    }
}
