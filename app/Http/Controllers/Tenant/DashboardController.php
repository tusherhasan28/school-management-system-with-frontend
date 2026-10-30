<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\Setup\Services\SetupWizard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SetupWizard $setup): Response
    {
        $user = $request->user();
        $session = AcademicSession::query()->where('is_current', true)->first(['id', 'name']);

        $byClass = $session && $user->can('students.view')
            ? StudentEnrollment::query()
                ->join('classes', function ($join) {
                    $join->on('classes.id', '=', 'student_enrollments.class_id')
                        ->on('classes.school_id', '=', 'student_enrollments.school_id');
                })
                ->where('student_enrollments.academic_session_id', $session->id)
                ->where('student_enrollments.status', 'enrolled')
                ->groupBy('classes.id', 'classes.name', 'classes.level_order')
                ->orderBy('classes.level_order')
                ->get(['classes.name', DB::raw('COUNT(*) as total')])
                ->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total])
            : [];

        return Inertia::render('dashboard', [
            'session' => $session?->name,
            'stats' => [
                'students' => $user->can('students.view') ? Student::query()->where('status', 'active')->count() : null,
                'staff' => $user->can('staff.view') ? StaffMember::query()->where('status', 'active')->count() : null,
                'pendingAdmissions' => $user->can('admissions.manage') ? AdmissionApplication::query()->whereIn('status', ['submitted', 'under_review'])->count() : null,
            ],
            'byClass' => $byClass,
            'setup' => $user->can('settings.manage') && ! $setup->isFinished() ? $setup->progress() : null,
            'recentActivity' => $user->can('audit.view')
                ? ActivityLog::query()->with('user:id,name,school_id')->latest('id')->limit(6)->get()
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
