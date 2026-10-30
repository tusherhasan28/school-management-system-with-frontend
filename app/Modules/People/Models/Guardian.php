<?php

namespace App\Modules\People\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A parent or guardian. One guardian may be linked to several students and a
 * student may have several guardians.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $full_name
 * @property string|null $name_bn
 * @property string $phone
 * @property string|null $email
 * @property string|null $national_id
 */
class Guardian extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = ['full_name', 'name_bn', 'phone', 'alternate_phone', 'email', 'occupation', 'address', 'national_id'];

    protected $hidden = ['national_id'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted'];
    }

    /**
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')
            ->withPivot(['id', 'relationship', 'is_primary', 'can_pickup'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
