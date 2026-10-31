<?php

namespace App\Models;

use App\Enums\SchoolStatus;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Central record of a subscribing institution (the tenant).
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string $institution_type
 * @property SchoolStatus $status
 * @property string $timezone
 * @property string $locale
 * @property string $currency
 * @property Carbon|null $suspended_at
 */
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // slug and status are never mass assignable (set by provisioning/lifecycle only).
        'name', 'name_bn', 'institution_type', 'email', 'phone', 'address',
        'district', 'division', 'timezone', 'locale', 'primary_color',
    ];

    protected function casts(): array
    {
        return [
            'status' => SchoolStatus::class,
            'trial_ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return HasMany<\App\Modules\Billing\Models\Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Modules\Billing\Models\Subscription::class);
    }

    /**
     * @return HasMany<SchoolDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(SchoolDomain::class);
    }

    /**
     * @return HasOne<SchoolDomain, $this>
     */
    public function primaryDomain(): HasOne
    {
        return $this->hasOne(SchoolDomain::class)->where('is_primary', true);
    }

    public function isAccessible(): bool
    {
        return in_array($this->status->value, config('tenancy.accessible_statuses'), true);
    }

    public function url(string $path = '/'): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $domain = ($this->relationLoaded('primaryDomain') ? $this->primaryDomain?->domain : null)
            ?? $this->slug.'.'.config('tenancy.domain');

        return $scheme.'://'.$domain.'/'.ltrim($path, '/');
    }
}
