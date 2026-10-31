<?php

namespace App\Modules\Support\Models;

use App\Models\PlatformUser;
use App\Models\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time-limited permission for one platform user to view a school's support
 * snapshot. Requested by platform staff with a reason, approved by the
 * school, revocable by the school, expires on its own.
 *
 * @property int $id
 * @property int $school_id
 * @property int $platform_user_id
 * @property string $reason
 * @property int $duration_hours
 * @property string $status
 * @property Carbon|null $expires_at
 */
class SupportAccessGrant extends Model
{
    protected $fillable = ['school_id', 'platform_user_id', 'support_ticket_id', 'reason', 'duration_hours'];

    protected $attributes = ['status' => 'requested'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'approved' && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** Status as shown: an approved grant past its expiry reads as expired. */
    public function effectiveStatus(): string
    {
        return $this->status === 'approved' && ! $this->isActive() ? 'expired' : $this->status;
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
