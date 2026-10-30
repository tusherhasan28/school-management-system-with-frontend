<?php

namespace App\Modules\People\Services;

use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentGuardian;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardianService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Bangladesh: 8801XXXXXXXXX / 01XXXXXXXXX → 01XXXXXXXXX
        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    }

    /**
     * Reuse an existing guardian with the same phone and name (so siblings
     * share one guardian record), otherwise create one.
     *
     * @param  array<string, mixed>  $data
     */
    public function findOrCreate(array $data): Guardian
    {
        $phone = self::normalizePhone((string) $data['phone']);

        $existing = Guardian::query()
            ->where('phone', $phone)
            ->whereRaw('LOWER(full_name) = ?', [mb_strtolower(trim((string) $data['full_name']))])
            ->first();

        if ($existing) {
            return $existing;
        }

        $guardian = Guardian::create(['phone' => $phone] + $data);
        $this->audit->tenant('guardian.created', $guardian, new: $guardian->only(['full_name', 'phone', 'email']));

        return $guardian;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Guardian $guardian, array $data): Guardian
    {
        if (isset($data['phone'])) {
            $data['phone'] = self::normalizePhone((string) $data['phone']);
        }

        $old = $guardian->only(array_keys($data));
        $guardian->update($data);
        $this->audit->tenant('guardian.updated', $guardian, $old, $data);

        return $guardian;
    }

    public function link(Student $student, Guardian $guardian, string $relationship, bool $primary = false, bool $canPickup = true): StudentGuardian
    {
        return DB::transaction(function () use ($student, $guardian, $relationship, $primary, $canPickup) {
            if ($primary) {
                StudentGuardian::query()->where('student_id', $student->id)->update(['is_primary' => false]);
            }

            $link = StudentGuardian::query()->firstOrNew(['student_id' => $student->id, 'guardian_id' => $guardian->id]);
            $link->fill(['relationship' => $relationship, 'is_primary' => $primary, 'can_pickup' => $canPickup])->save();

            // A student always has a primary guardian if it has any.
            if (! StudentGuardian::query()->where('student_id', $student->id)->where('is_primary', true)->exists()) {
                $link->forceFill(['is_primary' => true])->save();
            }

            $this->audit->tenant('student.guardian_linked', $student, new: ['guardian_id' => $guardian->id, 'relationship' => $relationship]);

            return $link;
        });
    }

    public function unlink(Student $student, Guardian $guardian): void
    {
        DB::transaction(function () use ($student, $guardian) {
            $count = StudentGuardian::query()->where('student_id', $student->id)->count();

            if ($count <= 1) {
                throw ValidationException::withMessages(['guardian' => __('people.last_guardian')]);
            }

            $link = StudentGuardian::query()->where('student_id', $student->id)->where('guardian_id', $guardian->id)->firstOrFail();
            $wasPrimary = $link->is_primary;
            $link->delete();

            if ($wasPrimary) {
                StudentGuardian::query()->where('student_id', $student->id)->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
            }

            $this->audit->tenant('student.guardian_unlinked', $student, ['guardian_id' => $guardian->id]);
        });
    }
}
