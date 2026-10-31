<?php

namespace App\Modules\Exams\Models;

use App\Modules\Academics\Models\AcademicSession;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * draft -> marks_entry -> calculated -> approved -> published.
 * Results always come from current_calculation_id; earlier versions are kept.
 *
 * @property int $id
 * @property int $academic_session_id
 * @property int|null $exam_type_id
 * @property int $grading_scale_id
 * @property string $name
 * @property string|null $name_bn
 * @property \Illuminate\Support\Carbon|null $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 * @property string $status
 * @property int|null $current_calculation_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class Examination extends Model
{
    use BelongsToSchool;

    public const STATUSES = ['draft', 'marks_entry', 'calculated', 'approved', 'published'];

    /** Mirrors the column default so a freshly created exam is a draft in memory too. */
    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['academic_session_id', 'exam_type_id', 'grading_scale_id', 'name', 'name_bn', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Setup (subjects, components, scale) can change only before marks entry starts. */
    public function isConfigurable(): bool
    {
        return $this->status === 'draft';
    }

    public function acceptsMarks(): bool
    {
        return $this->status === 'marks_entry';
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /** @return BelongsTo<ExamType, $this> */
    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    /** @return BelongsTo<GradingScale, $this> */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    /** @return HasMany<ExamSubject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }

    /** @return BelongsTo<ResultCalculation, $this> */
    public function currentCalculation(): BelongsTo
    {
        return $this->belongsTo(ResultCalculation::class, 'current_calculation_id');
    }

    /** @return HasMany<ResultCalculation, $this> */
    public function calculations(): HasMany
    {
        return $this->hasMany(ResultCalculation::class);
    }
}
