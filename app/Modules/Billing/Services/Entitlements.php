<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * What the current school may use, from its subscription's plan.
 *
 * Read only from the database (plan_features) for the school resolved from
 * the host, so nothing a browser sends can raise a limit. A school without
 * any subscription is "unmanaged" (created before billing was set up) and is
 * not limited; the platform dashboard lists such schools.
 */
class Entitlements
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly UsageMeter $usage,
    ) {}

    public function subscription(): ?Subscription
    {
        return Subscription::currentFor($this->tenant->require()->getKey());
    }

    /** @return array<string, int|bool|null>|null  null when unmanaged */
    public function values(): ?array
    {
        return $this->subscription()?->plan?->featureValues();
    }

    public function enabled(string $feature): bool
    {
        $values = $this->values();

        return $values === null || (bool) ($values[$feature] ?? false);
    }

    /** The limit, or null for unlimited (or unmanaged). */
    public function limit(string $key): ?int
    {
        $values = $this->values();

        return $values === null ? null : ($values[$key] ?? 0);
    }

    public function remaining(string $key): ?int
    {
        $limit = $this->limit($key);

        return $limit === null ? null : max(0, $limit - $this->usage->usage($key));
    }

    /** Refuse an action that would take usage past the plan's limit. */
    public function ensureCanAdd(string $key, int $count = 1): void
    {
        $remaining = $this->remaining($key);

        if ($remaining !== null && $remaining < $count) {
            throw ValidationException::withMessages(['limit' => __('billing.limit_reached', [
                'limit' => $this->limit($key), 'what' => __('billing.feature.'.$key),
            ])]);
        }
    }

    /** Refuse an action whose feature is not in the plan. */
    public function ensureEnabled(string $feature): void
    {
        if (! $this->enabled($feature)) {
            throw ValidationException::withMessages(['plan' => __('billing.feature_not_in_plan', ['feature' => __('billing.feature.'.$feature)])]);
        }
    }

    /**
     * Plan, limits and usage for the school's subscription screen.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $values = $this->values();

        return collect(PlanFeatures::CATALOGUE)->map(fn ($def, $key) => [
            'key' => $key, 'type' => $def['type'], 'unit' => $def['unit'] ?? null,
            'value' => $values === null ? ($def['type'] === 'switch' ? true : null) : $values[$key],
            'used' => $def['type'] === 'limit' ? $this->usage->usage($key) : null,
        ])->values()->all();
    }
}
