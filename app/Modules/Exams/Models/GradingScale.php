<?php

namespace App\Modules\Exams\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school's grading configuration: grade bands (items) plus rules
 * (see App\Modules\Exams\Grading\GradingRules for the keys).
 *
 * Locked once a published result used it; changes then need a copy.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $rules
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $locked_at
 */
class GradingScale extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name', 'description', 'rules', 'is_default'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_default' => 'boolean', 'locked_at' => 'datetime'];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** @return HasMany<GradingScaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GradingScaleItem::class)->orderByDesc('min_percentage');
    }
}
