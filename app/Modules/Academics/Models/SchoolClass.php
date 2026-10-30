<?php

namespace App\Modules\Academics\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class / grade / year level ("Class 6", "HSC 1st Year", "Play"). Labels are
 * configured per school; level_order defines promotion order.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $code
 * @property int $level_order
 * @property string $education_stage
 * @property bool $is_active
 */
class SchoolClass extends Model
{
    use BelongsToSchool;

    protected $table = 'classes';

    protected $fillable = ['name', 'name_bn', 'code', 'level_order', 'education_stage', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'level_order' => 'integer'];
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_id');
    }

    /**
     * The next class by level_order, used as the promotion target.
     */
    public function next(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('level_order', '>', $this->level_order)
            ->orderBy('level_order')
            ->first();
    }
}
