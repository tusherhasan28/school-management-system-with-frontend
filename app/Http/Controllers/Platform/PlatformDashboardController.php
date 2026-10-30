<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformActivityLog;
use App\Models\School;
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

        return Inertia::render('platform/dashboard', [
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
