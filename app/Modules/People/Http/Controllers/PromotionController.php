<?php

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\People\Services\EnrollmentLifecycle;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PromotionController extends Controller
{
    public function index(Request $request, AcademicOptions $options): Response
    {
        $this->authorize('promote', Student::class);

        $filters = $request->validate([
            'from_session' => ['nullable', 'integer'],
            'class' => ['nullable', 'integer'],
            'section' => ['nullable', 'integer'],
        ]);

        $students = [];

        if (! empty($filters['from_session']) && ! empty($filters['class'])) {
            $students = StudentEnrollment::query()
                ->with('student:id,full_name,name_bn,admission_no,school_id')
                ->where('academic_session_id', $filters['from_session'])
                ->where('class_id', $filters['class'])
                ->when($filters['section'] ?? null, fn ($q, $s) => $q->where('section_id', $s))
                ->where('status', 'enrolled')
                ->orderByRaw('CAST(roll_no AS UNSIGNED), roll_no')
                ->get()
                ->map(fn (StudentEnrollment $e) => [
                    'student_id' => $e->student_id,
                    'full_name' => $e->student?->full_name,
                    'name_bn' => $e->student?->name_bn,
                    'admission_no' => $e->student?->admission_no,
                    'roll_no' => $e->roll_no,
                ]);
        }

        return Inertia::render('app/students/promotions', [
            'filters' => $filters,
            'sessions' => $options->sessions(),
            'classes' => $options->classes(),
            'students' => $students,
        ]);
    }

    public function store(Request $request, EnrollmentLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('promote', Student::class);

        $data = $request->validate([
            'from_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
            'to_session_id' => ['required', 'integer', 'different:from_session_id', TenantRule::exists('academic_sessions')],
            'to_class_id' => ['required', 'integer', TenantRule::exists('classes')],
            'to_section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $request->integer('to_class_id'))],
            'mode' => ['required', Rule::in(['promoted', 'repeated'])],
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['integer', TenantRule::exists('students')],
        ]);

        $result = $lifecycle->move(
            $data['student_ids'],
            AcademicSession::query()->findOrFail($data['from_session_id']),
            AcademicSession::query()->findOrFail($data['to_session_id']),
            (int) $data['to_class_id'],
            $data['to_section_id'] ?? null,
            $data['mode'],
        );

        Inertia::flash('toast', [
            'type' => $result['skipped'] === [] ? 'success' : 'warning',
            'message' => __('people.promotion_done', ['moved' => $result['moved'], 'skipped' => count($result['skipped'])]),
        ]);

        return back();
    }
}
