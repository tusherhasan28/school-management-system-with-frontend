<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Finance\Gateways\GatewayRegistry;
use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use App\Modules\Finance\Services\FinanceSettings;
use App\Services\Audit\AuditLogger;
use App\Support\Localization\Localized;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Fee categories, fee plans per session, finance settings and gateway accounts. */
class FeeSetupController extends Controller
{
    public function __construct(private readonly FinanceSettings $settings, private readonly AuditLogger $audit) {}

    public function index(Request $request, AcademicOptions $options, GatewayRegistry $gateways): Response
    {
        $this->authorizeManage($request);
        $sessionId = (int) ($request->integer('session') ?: $options->currentSessionId());
        $session = AcademicSession::query()->find($sessionId);
        $accounts = PaymentGatewayAccount::query()->get()->keyBy('gateway');

        return Inertia::render('app/fees/setup', [
            'sessionId' => $sessionId,
            'session' => $session ? ['start' => $session->start_date->format('Y-m'), 'end' => $session->end_date->format('Y-m')] : null,
            'sessions' => $options->sessions(),
            'classes' => $options->classes(),
            'categories' => FeeCategory::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($c) => $c->only(['id', 'name', 'name_bn', 'description', 'is_active']) + ['label' => Localized::name($c->name, $c->name_bn)]),
            'structures' => FeeStructure::query()->with(['category:id,school_id,name,name_bn', 'schoolClass:id,school_id,name,level_order'])
                ->where('academic_session_id', $sessionId)->orderByDesc('is_active')->orderBy('class_id')->orderBy('name')->get()
                ->map(fn (FeeStructure $s) => $s->only(['id', 'name', 'amount', 'frequency', 'start_period', 'applies_to', 'is_active', 'class_id', 'fee_category_id']) + [
                    'category' => Localized::name($s->category?->name, $s->category?->name_bn), 'class' => $s->schoolClass?->name,
                ]),
            'settings' => $this->settings->all(),
            'gateways' => collect($gateways->all())->map(fn ($g, $key) => [
                'key' => $key, 'label' => $g->label(), 'fields' => $g->credentialFields(),
                'enabled' => (bool) $accounts->get($key)?->is_enabled, 'mode' => $accounts->get($key)?->mode ?? 'sandbox', 'configured' => $accounts->has($key),
            ])->values(),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        FeeCategory::create($request->validate([
            'name' => ['required', 'string', 'max:120', TenantRule::unique('fee_categories', 'name')],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]) + ['sort_order' => (int) FeeCategory::query()->max('sort_order') + 1]);

        return back();
    }

    public function updateCategory(Request $request, FeeCategory $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $category->update($request->validate([
            'name' => ['required', 'string', 'max:120', TenantRule::unique('fee_categories', 'name')->ignore($category->id)],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]));

        return back();
    }

    public function storeStructure(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validateStructure($request);
        $structure = FeeStructure::create($data);
        $this->audit->tenant('finance.structure_created', $structure, [], $data);

        return back();
    }

    /** Amount and schedule may change for future invoices; issued invoices keep their own lines. */
    public function updateStructure(Request $request, FeeStructure $structure): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validateStructure($request, $structure);
        $old = $structure->only(array_keys($data));
        $structure->update($data);
        $this->audit->tenant('finance.structure_updated', $structure, $old, $data);

        return back();
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'due_day' => ['required', 'integer', 'between:1,28'],
            'late_fee_enabled' => ['boolean'],
            'late_fee_kind' => ['required', Rule::in(['fixed', 'percent'])],
            'late_fee_value' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,2'],
            'late_fee_grace_days' => ['required', 'integer', 'between:0,90'],
            'receipt_note' => ['nullable', 'string', 'max:255'],
        ]);

        if (($data['late_fee_kind'] ?? '') === 'percent' && Money::parse((string) $data['late_fee_value']) > 10000) {
            throw ValidationException::withMessages(['late_fee_value' => __('finance.percent_max')]);
        }

        $data['late_fee_value'] = Money::format(Money::parse((string) $data['late_fee_value']));
        $data['late_fee_enabled'] = (bool) ($data['late_fee_enabled'] ?? false);
        $this->settings->save($data);
        $this->audit->tenant('finance.settings_updated', null, [], $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('finance.settings_saved')]);

        return back();
    }

    /** Credentials for an installed adapter. Secrets are encrypted and never sent back to the browser. */
    public function saveGateway(Request $request, string $gateway, GatewayRegistry $gateways): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage') && $request->user()->can('fees.manage'), 403);
        $adapter = $gateways->get($gateway) ?? abort(404);
        $rules = ['mode' => ['required', Rule::in(['sandbox', 'live'])], 'is_enabled' => ['boolean']];

        foreach ($adapter->credentialFields() as $f) {
            $rules['credentials.'.$f['key']] = ['nullable', 'string', 'max:500'];
        }

        $data = $request->validate($rules);
        $account = PaymentGatewayAccount::query()->firstOrNew(['gateway' => $gateway]);
        $current = $account->exists ? $account->credentials : [];

        // Empty secret fields keep the stored value.
        $credentials = collect($adapter->credentialFields())->mapWithKeys(fn ($f) => [
            $f['key'] => ($data['credentials'][$f['key']] ?? '') !== '' ? $data['credentials'][$f['key']] : ($current[$f['key']] ?? ''),
        ])->all();

        $account->fill(['mode' => $data['mode'], 'is_enabled' => (bool) ($data['is_enabled'] ?? false), 'credentials' => $credentials])->save();
        $this->audit->tenant('finance.gateway_saved', $account, [], ['gateway' => $gateway, 'mode' => $data['mode'], 'enabled' => $account->is_enabled]);

        return back();
    }

    /** @return array<string, mixed> */
    private function validateStructure(Request $request, ?FeeStructure $structure = null): array
    {
        $data = $request->validate([
            'academic_session_id' => [$structure ? 'prohibited' : 'required', 'integer', TenantRule::exists('academic_sessions')],
            'class_id' => ['nullable', 'integer', TenantRule::exists('classes')],
            'fee_category_id' => ['required', 'integer', TenantRule::exists('fee_categories')],
            'name' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
            'frequency' => ['required', Rule::in(array_keys(FeeStructure::FREQUENCIES))],
            'start_period' => ['required', 'date_format:Y-m'],
            'applies_to' => ['required', Rule::in(['class', 'assigned'])],
            'is_active' => ['boolean'],
        ]);

        $session = AcademicSession::query()->findOrFail($structure?->academic_session_id ?? $data['academic_session_id']);

        if ($data['start_period'] < $session->start_date->format('Y-m') || $data['start_period'] > $session->end_date->format('Y-m')) {
            throw ValidationException::withMessages(['start_period' => __('finance.period_outside_session')]);
        }

        $data['amount'] = Money::format(Money::parse((string) $data['amount']));

        return $data;
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('fees.manage'), 403);
    }
}
