<?php

namespace App\Support\Permissions;

/**
 * Catalogue of tenant permissions and the default roles a new school gets.
 * Schools may customise roles later; permission names are global.
 * After changing this file run `php artisan tenancy:sync-permissions`.
 */
final class TenantPermissions
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'dashboard.view',
            'campuses.view', 'campuses.manage',
            'settings.manage', 'users.manage', 'roles.manage', 'audit.view',
            'academics.view', 'academics.manage',
            'students.view', 'students.create', 'students.update', 'students.export', 'students.promote',
            'guardians.view', 'guardians.manage',
            'staff.view', 'staff.manage',
            'admissions.manage', 'imports.manage',
            'assignments.manage', 'timetable.view', 'timetable.manage', 'calendar.manage',
            // attendance.mark / homework.manage are limited to the teacher's own
            // assignments unless the user also has the *.view_all permission.
            'attendance.mark', 'attendance.correct', 'attendance.view_all',
            'homework.manage', 'homework.view_all',
            // marks.enter is limited to the teacher's own subjects/sections;
            // marks.enter_all covers every mark sheet.
            'exams.manage', 'marks.enter', 'marks.enter_all', 'marks.verify',
            'results.view', 'results.approve', 'results.publish',
            // Declared now so roles are stable; enforced as modules land.
            'fees.collect', 'fees.refund',
            'notices.manage', 'website.manage',
        ];
    }

    /**
     * @return array<string, list<string>|string>
     */
    public static function defaultRoles(): array
    {
        return [
            'School Owner' => '*',
            'School Admin' => '*',
            'Principal' => [
                'dashboard.view', 'campuses.view', 'audit.view', 'academics.view', 'academics.manage',
                'students.view', 'students.export', 'students.promote', 'guardians.view', 'staff.view',
                'admissions.manage', 'assignments.manage', 'timetable.view', 'timetable.manage', 'calendar.manage',
                'attendance.mark', 'attendance.correct', 'attendance.view_all', 'homework.view_all',
                'exams.manage', 'marks.verify', 'results.view', 'results.approve', 'results.publish', 'notices.manage',
            ],
            'Exam Controller' => [
                'dashboard.view', 'academics.view', 'students.view', 'timetable.view',
                'exams.manage', 'marks.enter', 'marks.enter_all', 'marks.verify', 'results.view',
            ],
            'Accountant' => ['dashboard.view', 'students.view', 'guardians.view', 'fees.collect', 'fees.refund'],
            'Admission Officer' => [
                'dashboard.view', 'academics.view', 'students.view', 'students.create', 'students.update',
                'guardians.view', 'guardians.manage', 'admissions.manage', 'imports.manage',
            ],
            // Teachers see students only through their class/subject assignments (TeachingScope).
            'Teacher' => ['dashboard.view', 'academics.view', 'timetable.view', 'attendance.mark', 'homework.manage', 'marks.enter'],
            'Class Teacher' => ['dashboard.view', 'academics.view', 'timetable.view', 'attendance.mark', 'attendance.correct', 'homework.manage', 'marks.enter'],
            'Librarian' => ['dashboard.view'],
            'Transport Manager' => ['dashboard.view'],
            'Receptionist' => ['dashboard.view', 'students.view', 'guardians.view', 'admissions.manage', 'timetable.view'],
            'Student' => [],
            'Parent' => [],
        ];
    }

    /**
     * Roles that may be given to a staff login from the staff screen.
     *
     * @return list<string>
     */
    public static function staffRoles(): array
    {
        return ['School Admin', 'Principal', 'Exam Controller', 'Accountant', 'Admission Officer', 'Teacher', 'Class Teacher', 'Librarian', 'Transport Manager', 'Receptionist'];
    }
}
