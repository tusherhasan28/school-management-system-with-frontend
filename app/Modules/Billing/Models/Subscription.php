<?php

namespace App\Modules\Billing\Models;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A school's subscription to a plan (central). Changed only through
 * SubscriptionService, never by tenant requests.
 *
 * @property int $id
 * @property int $school_id
 * @property int $plan_id
 * @property string $status
 * @property string $billing_cycle
 * @property string $amount
 * @property Carbon $starts_at
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $grace_ends_at
 * @property bool $auto_renew
 * @property bool $cancel_at_period_end
 */
class Subscription extends Model
{
    public const STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired'];

    /** Statuses of a subscription that is still the school's current one. */
    public const LIVE = ['trialing', 'active', 'past_due', 'suspended'];

    protected $fillable = ['school_id', 'plan_id', 'status', 'billing_cycle', 'amount', 'starts_at', 'current_period_start', 'current_period_end', 'trial_ends_at', 'auto_renew', 'notes'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'starts_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime', 'grace_ends_at' => 'datetime', 'suspended_at' => 'datetime', 'cancelled_at' => 'datetime', 'ended_at' => 'datetime',
            'auto_renew' => 'boolean', 'cancel_at_period_end' => 'boolean',
        ];
    }

    /** @param  Builder<Subscription>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }

    /** The school's current subscription, if any. */
    public static function currentFor(int $schoolId): ?self
    {
        return self::query()->with('plan.features')->where('school_id', $schoolId)->live()->latest('id')->first();
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<PlatformInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }
}
