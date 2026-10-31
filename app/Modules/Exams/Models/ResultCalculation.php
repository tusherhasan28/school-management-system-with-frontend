<?php

namespace App\Modules\Exams\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One numbered run of the grading engine for an examination, with the exact
 * configuration it used. Rows are never updated or deleted.
 *
 * @property int $id
 * @property int $examination_id
 * @property int $version
 * @property string $engine_version
 * @property array<string, mixed> $grading_snapshot
 * @property array<int, mixed> $setup_snapshot
 * @property string $config_hash
 * @property string $input_hash
 * @property int $student_count
 * @property \Illuminate\Support\Carbon $calculated_at
 */
class ResultCalculation extends Model
{
    use BelongsToSchool;

    protected $fillable = ['examination_id', 'version', 'engine_version', 'grading_snapshot', 'setup_snapshot', 'config_hash', 'input_hash', 'student_count', 'calculated_by', 'calculated_at'];

    protected function casts(): array
    {
        return ['grading_snapshot' => 'array', 'setup_snapshot' => 'array', 'calculated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Result calculations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Result calculations are immutable.'));
    }

    /** @return HasMany<ExamResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    /** @return BelongsTo<User, $this> */
    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }
}
