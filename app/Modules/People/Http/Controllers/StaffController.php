<?php

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Services\StaffService;
use App\Support\Permissions\TenantPermissions;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StaffMember::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['teacher', 'staff'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'on_leave'])],
        ]);

        $members = StaffMember::query()
            ->with('user:id,status,school_id')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$s}%")->orWhere('name_bn', 'like', "%{$s}%")
                ->orWhere('employee_no', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('staff_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('full_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (StaffMember $m) => $m->only(['id', 'employee_no', 'full_name', 'name_bn', 'staff_type', 'designation', 'phone', 'email', 'status']) + [
                'login' => $m->user?->status,
            ]);

        return Inertia::render('app/staff/index', [
            'staff' => $members,
            'filters' => ['search' => $filters['search'] ?? '', 'type' => $filters['type'] ?? '', 'status' => $filters['status'] ?? ''],
            'can' => ['create' => $request->user()->can('create', StaffMember::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', StaffMember::class);

        return Inertia::render('app/staff/form', [
            'member' => null,
            'roles' => $request->user()->can('users.manage') ? TenantPermissions::staffRoles() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', StaffMember::class);
        $data = $this->validated($request);
        $role = $request->user()->can('users.manage') ? ($data['login_role'] ?? null) : null;

        $member = $this->staff->create(Arr::except($data, ['login_role']), $role ?: null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return to_route('staff.edit', $member);
    }

    public function edit(Request $request, StaffMember $staff): Response
    {
        $this->authorize('view', $staff);
        $staff->load('user:id,status,email,school_id');

        return Inertia::render('app/staff/form', [
            'member' => [
                ...$staff->only(['id', 'staff_type', 'employee_no', 'full_name', 'name_bn', 'gender', 'phone', 'email', 'designation', 'department', 'qualification', 'address', 'status']),
                'date_of_birth' => $staff->date_of_birth?->toDateString(),
                'joining_date' => $staff->joining_date?->toDateString(),
                'login' => $staff->user ? ['status' => $staff->user->status, 'roles' => $staff->user->getRoleNames()] : null,
            ],
            'roles' => $request->user()->can('createLogin', $staff) ? TenantPermissions::staffRoles() : [],
            'can' => ['update' => $request->user()->can('update', $staff)],
        ]);
    }

    public function update(Request $request, StaffMember $staff): RedirectResponse
    {
        $this->authorize('update', $staff);
        $this->staff->update($staff, Arr::except($this->validated($request, $staff), ['login_role']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }

    public function createLogin(Request $request, StaffMember $staff): RedirectResponse
    {
        $this->authorize('createLogin', $staff);
        $data = $request->validate(['role' => ['required', Rule::in(TenantPermissions::staffRoles())]]);

        $this->staff->createLogin($staff, $data['role']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invitation.sent')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?StaffMember $staff = null): array
    {
        return $request->validate([
            'staff_type' => ['required', Rule::in(['teacher', 'staff'])],
            'employee_no' => ['nullable', 'string', 'max:60', 'alpha_dash', TenantRule::unique('staff_members', 'employee_no')->ignore($staff?->id)],
            'full_name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'gender' => ['required', Rule::in(['male', 'female', 'other', 'not_specified'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'designation' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'qualification' => ['nullable', 'string', 'max:190'],
            'joining_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'on_leave'])],
            'login_role' => ['nullable', Rule::in(TenantPermissions::staffRoles())],
        ]);
    }
}
