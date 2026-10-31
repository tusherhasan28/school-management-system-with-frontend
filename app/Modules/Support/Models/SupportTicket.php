<?php

namespace App\Modules\Support\Models;

use App\Models\PlatformUser;
use App\Models\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A school's support request (central). Tenant screens always read it with
 * an explicit school_id from the resolved host.
 *
 * @property int $id
 * @property int $school_id
 * @property string $reference
 * @property string $subject
 * @property string $category
 * @property string $priority
 * @property string $status
 * @property Carbon|null $last_activity_at
 */
class SupportTicket extends Model
{
    public const STATUSES = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];

    public const CATEGORIES = ['technical', 'billing', 'data', 'feature', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = ['school_id', 'reference', 'created_by_user_id', 'subject', 'category', 'priority'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'assigned_platform_user_id');
    }
}
