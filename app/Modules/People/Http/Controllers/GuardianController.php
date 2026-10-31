<?php

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\People\Services\GuardianService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuardianController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Guardian::class);

        $search = trim((string) $request->query('search', ''));

        $guardians = Guardian::query()
            ->withCount('students')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$search}%")->orWhere('name_bn', 'like', "%{$search}%")
                ->orWhere('phone', 'like', '%'.GuardianService::normalizePhone($search).'%')))
            ->orderBy('full_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Guardian $g) => $g->only(['id', 'full_name', 'name_bn', 'phone', 'email', 'occupation']) + ['students_count' => $g->students_count]);

        return Inertia::render('app/guardians/index', ['guardians' => $guardians, 'filters' => ['search' => $search]]);
    }

    public function show(Request $request, Guardian $guardian): Response
    {
        $this->authorize('view', $guardian);
        $guardian->load('students:id,full_name,name_bn,admission_no,status,school_id');

        return Inertia::render('app/guardians/show', [
            'guardian' => $guardian->only(['id', 'full_name', 'name_bn', 'phone', 'alternate_phone', 'email', 'occupation', 'address']) + [
                'has_national_id' => $guardian->national_id !== null,
                'students' => $guardian->students->map(fn (Student $s) => $s->only(['id', 'full_name', 'name_bn', 'admission_no', 'status']) + [
                    'relationship' => $s->pivot->relationship,
                ]),
            ],
            'can' => ['update' => $request->user()->can('update', $guardian)],
            'portal' => $request->user()->can('users.manage') ? ['status' => app(\App\Modules\Portal\Services\PortalAccounts::class)->status($guardian)] : null,
        ]);
    }

    public function update(Request $request, Guardian $guardian, GuardianService $service): RedirectResponse
    {
        $this->authorize('update', $guardian);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'alternate_phone' => ['nullable', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'national_id' => ['nullable', 'digits_between:10,17'],
        ]);

        if (blank($data['national_id'] ?? null)) {
            unset($data['national_id']); // blank keeps the stored (encrypted) value
        }

        $service->update($guardian, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }
}
