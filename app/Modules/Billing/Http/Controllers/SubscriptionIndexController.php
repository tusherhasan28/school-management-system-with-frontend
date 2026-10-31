<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Platform: every school's current subscription, plus schools without one. */
class SubscriptionIndexController extends Controller
{
    public function __invoke(Request $request, BillingPresenter $present): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([...Subscription::STATUSES, 'none'])]]);
        $status = $filters['status'] ?? null;

        $subscriptions = $status === 'none' ? null : Subscription::query()->with(['plan', 'school:id,name,slug,status'])
            ->when($status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->live())
            ->orderByRaw("FIELD(status, 'past_due', 'suspended', 'trialing', 'active', 'cancelled', 'expired')")->orderBy('current_period_end')
            ->paginate(25)->withQueryString()
            ->through(fn (Subscription $s) => $present->subscription($s) + ['school' => ['id' => $s->school->id, 'name' => $s->school->name, 'slug' => $s->school->slug]]);

        $unmanaged = School::query()->whereDoesntHave('subscriptions', fn ($q) => $q->live())->whereNotIn('status', ['archived'])
            ->orderBy('name')->limit(100)->get(['id', 'name', 'slug', 'status'])
            ->map(fn (School $s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug, 'status' => $s->status->value]);

        $counts = Subscription::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('platform/subscriptions/index', [
            'subscriptions' => $subscriptions,
            'unmanaged' => $unmanaged,
            'counts' => collect(Subscription::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all() + ['none' => $unmanaged->count()],
            'filters' => ['status' => $status ?? ''],
        ]);
    }
}
