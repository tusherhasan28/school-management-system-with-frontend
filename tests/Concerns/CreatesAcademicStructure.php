<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Services\AcademicSessionService;
use App\Modules\People\Models\Student;
use App\Modules\People\Services\StudentService;

/**
 * Builds a session, classes and sections inside the CURRENT tenant context.
 */
trait CreatesAcademicStructure
{
    /**
     * @return array{session: AcademicSession, class6: SchoolClass, class7: SchoolClass, sectionA: Section, sectionB: Section, class7A: Section}
     */
    protected function academicStructure(string $sessionName = '2026'): array
    {
        $session = AcademicSession::create(['name' => $sessionName, 'start_date' => $sessionName.'-01-01', 'end_date' => $sessionName.'-12-31']);
        app(AcademicSessionService::class)->makeCurrent($session);

        $class6 = SchoolClass::create(['name' => 'Class 6', 'name_bn' => 'ষষ্ঠ শ্রেণি', 'level_order' => 6, 'education_stage' => 'secondary']);
        $class7 = SchoolClass::create(['name' => 'Class 7', 'level_order' => 7, 'education_stage' => 'secondary']);

        return [
            'session' => $session,
            'class6' => $class6,
            'class7' => $class7,
            'sectionA' => Section::create(['class_id' => $class6->id, 'name' => 'A']),
            'sectionB' => Section::create(['class_id' => $class6->id, 'name' => 'B']),
            'class7A' => Section::create(['class_id' => $class7->id, 'name' => 'A']),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeStudent(AcademicSession $session, SchoolClass $class, ?Section $section = null, array $overrides = [], string $guardianPhone = '01711000000', ?string $roll = null): Student
    {
        $guardianName = $overrides['guardian_name'] ?? 'Guardian '.$guardianPhone;
        unset($overrides['guardian_name']);

        return app(StudentService::class)->create(
            ['full_name' => $overrides['full_name'] ?? fake()->name(), 'gender' => 'female'] + $overrides,
            [['full_name' => $guardianName, 'phone' => $guardianPhone, 'relationship' => 'father']],
            ['academic_session_id' => $session->id, 'class_id' => $class->id, 'section_id' => $section?->id, 'roll_no' => $roll],
        );
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
