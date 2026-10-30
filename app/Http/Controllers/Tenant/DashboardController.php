<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Modules\Setup\Models\Campus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'stats' => [
                'campuses' => Campus::query()->count(),
                'users' => User::query()->count(),
            ],
            'recentActivity' => $user->can('audit.view')
                ? ActivityLog::query()
                    ->with('user:id,name')
                    ->latest('id')
                    ->limit(6)
                    ->get()
                    ->map(fn (ActivityLog $log) => [
                        'id' => $log->id,
                        'action' => $log->action,
                        'user' => $log->user?->name,
                        'created_at' => $log->created_at?->toIso8601String(),
                    ])
                : [],
        ]);
    }
}
