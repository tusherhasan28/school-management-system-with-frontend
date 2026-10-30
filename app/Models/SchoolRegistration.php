<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central: a request from an institution to join the platform.
 *
 * @property int $id
 * @property string $institution_name
 * @property string|null $institution_name_bn
 * @property string $requested_slug
 * @property string $institution_type
 * @property string $contact_name
 * @property string $email
 * @property string $phone
 * @property string $status
 * @property int|null $school_id
 */
class SchoolRegistration extends Model
{
    protected $fillable = [
        'institution_name', 'institution_name_bn', 'requested_slug', 'institution_type', 'contact_name',
        'email', 'phone', 'district', 'address', 'approx_students',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
