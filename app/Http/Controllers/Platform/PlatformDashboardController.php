<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformActivityLog;
use App\Models\School;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Support\Models\SupportTicket;
use App\Support\Money\Money;
use Inertia\Inertia;
use Inertia\Response;

class PlatformDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $counts = School::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = collect(SchoolStatus::cases())
            ->map(fn (SchoolStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'total' => (int) ($counts[$status->value] ?? 0),
            ])
            ->values();

        $recentActivity = PlatformActivityLog::query()
            ->latest('id')
            ->limit(8)
            ->get(['id', 'action', 'school_id', 'created_at'])
            ->map(fn (PlatformActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'school_id' => $log->school_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        $live = Subscription::query()->live()->whereIn('status', ['active', 'past_due']);
        // Monthly recurring revenue: yearly amounts counted per month, in paisa to avoid float drift.
        $mrr = (clone $live)->get(['amount', 'billing_cycle'])->sum(fn ($s) => intdiv(Money::parse($s->amount), $s->billing_cycle === 'yearly' ? 12 : 1));

        return Inertia::render('platform/dashboard', [
            'billing' => [
                'mrr' => Money::format($mrr),
                'trialing' => Subscription::query()->where('status', 'trialing')->count(),
                'past_due' => Subscription::query()->where('status', 'past_due')->count(),
                'overdue_total' => (string) PlatformInvoice::query()->overdue()->sum('total'),
                'unmanaged' => School::query()->whereDoesntHave('subscriptions', fn ($q) => $q->live())->whereNotIn('status', ['archived', 'pending'])->count(),
                'open_tickets' => SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count(),
            ],
            'stats' => [
                'schools' => (int) $counts->sum(),
                'active' => (int) (($counts['active'] ?? 0) + ($counts['trial'] ?? 0)),
                'suspended' => (int) ($counts['suspended'] ?? 0),
                'pending' => (int) ($counts['pending'] ?? 0),
            ],
            'byStatus' => $byStatus,
            'recentActivity' => $recentActivity,
        ]);
    }
}
