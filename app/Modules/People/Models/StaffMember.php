<?php

namespace App\Modules\People\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Teachers and non-teaching staff (staff_type).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $staff_type
 * @property string $employee_no
 * @property string $full_name
 * @property string|null $name_bn
 * @property string|null $email
 * @property string|null $phone
 * @property string $status
 */
class StaffMember extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = [
        'staff_type', 'employee_no', 'full_name', 'name_bn', 'gender', 'date_of_birth', 'phone', 'email',
        'designation', 'department', 'qualification', 'joining_date', 'address', 'status',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'joining_date' => 'date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
