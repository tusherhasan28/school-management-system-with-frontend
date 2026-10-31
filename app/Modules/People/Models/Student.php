<?php

namespace App\Modules\People\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $school_id
 * @property int|null $user_id
 * @property string $admission_no
 * @property string $full_name
 * @property string|null $name_bn
 * @property \Illuminate\Support\Carbon|null $date_of_birth
 * @property string $gender
 * @property string|null $birth_registration_no
 * @property string $status
 */
class Student extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = [
        'full_name', 'name_bn', 'date_of_birth', 'gender', 'blood_group', 'religion', 'nationality', 'email',
        'birth_registration_no', 'present_address', 'permanent_address', 'admission_date',
    ];

    /** Sensitive identifiers never leave the model through toArray()/JSON. */
    protected $hidden = ['birth_registration_no'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
            'birth_registration_no' => 'encrypted',
        ];
    }

    /**
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot(['id', 'relationship', 'is_primary', 'can_pickup'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<StudentEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * @return HasOne<StudentEnrollment, $this>
     */
    public function latestEnrollment(): HasOne
    {
        return $this->hasOne(StudentEnrollment::class)->latestOfMany();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
