<?php

namespace App\Modules\Exams\Models;

use App\Models\User;
use App\Modules\Academics\Models\Section;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marks of one exam subject for one section. draft -> submitted -> verified.
 * Verified sheets are read-only until a verifier returns them.
 *
 * @property int $id
 * @property int $exam_subject_id
 * @property int $class_id
 * @property int $section_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 */
class ExamMarkSheet extends Model
{
    use BelongsToSchool;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['exam_subject_id', 'class_id', 'section_id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<ExamSubject, $this> */
    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return HasMany<ExamMark, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(ExamMark::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
