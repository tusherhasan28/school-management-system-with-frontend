<?php

namespace App\Modules\Homework\Models;

use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Models\Subject;
use App\Modules\People\Models\StaffMember;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $academic_session_id
 * @property int $class_id
 * @property int|null $section_id
 * @property int $subject_id
 * @property int|null $staff_member_id
 * @property string $title
 * @property string|null $instructions
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property \Illuminate\Support\Carbon $assigned_on
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property string|null $max_marks
 * @property string $status
 */
class Homework extends Model
{
    use BelongsToSchool;

    protected $table = 'homework';

    protected $fillable = ['academic_session_id', 'class_id', 'section_id', 'subject_id', 'staff_member_id', 'title', 'instructions', 'assigned_on', 'due_at', 'max_marks', 'status'];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return ['assigned_on' => 'date', 'due_at' => 'datetime', 'max_marks' => 'decimal:2'];
    }

    /** @return HasMany<HomeworkSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<StaffMember, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id');
    }
}
