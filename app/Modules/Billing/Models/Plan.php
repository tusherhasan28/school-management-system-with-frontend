<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Services\PlanFeatures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS plan (central). Prices and limits are data, edited by platform
 * staff; nothing about a plan is hard-coded.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_bn
 * @property string $price_monthly
 * @property string $price_yearly
 * @property int $trial_days
 * @property int $grace_days
 * @property bool $is_active
 * @property bool $is_public
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'name_bn', 'description', 'price_monthly', 'price_yearly', 'trial_days', 'grace_days', 'is_active', 'is_public', 'sort_order'];

    protected function casts(): array
    {
        return ['price_monthly' => 'decimal:2', 'price_yearly' => 'decimal:2', 'is_active' => 'boolean', 'is_public' => 'boolean'];
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @param  Builder<Plan>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('price_monthly')->orderBy('id');
    }

    public function price(string $cycle): string
    {
        return $cycle === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }

    /**
     * Every catalogue feature with this plan's value (missing rows fall back
     * to the catalogue's fail-closed default).
     *
     * @return array<string, int|bool|null>
     */
    public function featureValues(): array
    {
        $stored = $this->features->mapWithKeys(fn (PlanFeature $f) => [$f->feature_key => $f->feature_value])->all();

        return collect(PlanFeatures::CATALOGUE)->mapWithKeys(fn ($def, $key) => [
            $key => array_key_exists($key, $stored) ? PlanFeatures::cast($key, $stored[$key]) : $def['default'],
        ])->all();
    }
}
