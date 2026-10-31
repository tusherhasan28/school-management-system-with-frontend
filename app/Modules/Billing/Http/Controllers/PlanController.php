<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanFeature;
use App\Modules\Billing\Services\BillingPresenter;
use App\Modules\Billing\Services\PlanFeatures;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Platform: plans and their features/limits (all stored in the database). */
class PlanController extends Controller
{
    public function __construct(private readonly BillingPresenter $present, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        return Inertia::render('platform/plans/index', [
            'plans' => Plan::query()->with('features')->withCount(['subscriptions' => fn ($q) => $q->live()])->ordered()->get()
                ->map(fn (Plan $p) => $this->present->plan($p) + ['subscriptions' => $p->subscriptions_count]),
            'catalogue' => collect(PlanFeatures::CATALOGUE)->map(fn ($d, $k) => ['key' => $k, 'type' => $d['type'], 'unit' => $d['unit'] ?? null])->values(),
            'can' => ['manage' => $request->user('platform')->can('billing.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new Plan);

        return back();
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $this->save($request, $plan);

        return back();
    }

    private function save(Request $request, Plan $plan): void
    {
        $rules = [
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('plans', 'code')->ignore($plan->id)],
            'name' => ['required', 'string', 'max:100'],
            'name_bn' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'price_yearly' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'features' => ['array'],
        ];

        foreach (PlanFeatures::CATALOGUE as $key => $def) {
            $rules['features.'.$key] = $def['type'] === 'limit' ? ['nullable', 'integer', 'min:0', 'max:100000000'] : ['boolean'];
        }

        $data = $request->validate($rules);
        $features = $data['features'] ?? [];
        unset($data['features']);
        $data['sort_order'] ??= 0;

        DB::transaction(function () use ($plan, $data, $features) {
            $old = $plan->exists ? $plan->load('features')->featureValues() : [];
            $plan->fill($data)->save();

            foreach (PlanFeatures::CATALOGUE as $key => $def) {
                // A limit left empty means unlimited; an unticked switch is off.
                $value = PlanFeatures::cast($key, $features[$key] ?? ($def['type'] === 'switch' ? false : null));
                PlanFeature::query()->updateOrCreate(['plan_id' => $plan->id, 'feature_key' => $key], ['feature_value' => $value]);
            }

            $this->audit->platform($plan->wasRecentlyCreated ? 'plan.created' : 'plan.updated', $plan, null, ['before' => $old, 'after' => $plan->load('features')->featureValues(), 'prices' => [$plan->price_monthly, $plan->price_yearly]]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved')]);
    }
}
