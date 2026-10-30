<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform view of schools. Reads only central data: no tenant records are
 * exposed here (support access to tenant data is a separate, audited flow).
 */
class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(SchoolStatus::class)],
            'sort' => ['nullable', Rule::in(['name', 'created_at', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $schools = School::query()
            ->with('primaryDomain')
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (School $school) => $this->present($school));

        return Inertia::render('platform/schools/index', [
            'schools' => $schools,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'sort' => $filters['sort'] ?? 'created_at',
                'direction' => $filters['direction'] ?? 'desc',
            ],
            'statuses' => collect(SchoolStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'can' => ['manage' => $request->user('platform')->can('schools.manage')],
        ]);
    }

    public function show(School $school): Response
    {
        $school->load('primaryDomain');

        return Inertia::render('platform/schools/show', [
            'school' => $this->present($school) + [
                'email' => $school->email,
                'phone' => $school->phone,
                'district' => $school->district,
                'suspension_reason' => $school->suspension_reason,
                'suspended_at' => $school->suspended_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'name_bn' => $school->name_bn,
            'slug' => $school->slug,
            'institution_type' => $school->institution_type,
            'status' => $school->status->value,
            'status_label' => $school->status->label(),
            'url' => $school->url(),
            'created_at' => $school->created_at?->toIso8601String(),
        ];
    }
}
