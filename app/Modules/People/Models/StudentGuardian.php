<?php

namespace App\Modules\People\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Link between a student and a guardian. Created through GuardianLinkService
 * so the tenant guards and audit apply (not via attach()).
 *
 * @property int $id
 * @property int $student_id
 * @property int $guardian_id
 * @property string $relationship
 * @property bool $is_primary
 */
class StudentGuardian extends Model
{
    use BelongsToSchool;

    protected $table = 'student_guardians';

    protected $fillable = ['student_id', 'guardian_id', 'relationship', 'is_primary', 'can_pickup'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'can_pickup' => 'boolean'];
    }
}
