<?php

namespace App\Modules\Academics\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $class_id
 * @property string $name
 * @property int|null $capacity
 * @property string|null $room
 * @property bool $is_active
 */
class Section extends Model
{
    use BelongsToSchool;

    protected $fillable = ['class_id', 'name', 'capacity', 'room', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
