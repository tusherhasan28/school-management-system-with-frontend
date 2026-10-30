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
            // Declared now so roles are stable; enforced as modules land.
            'attendance.mark', 'attendance.correct',
            'marks.enter', 'results.publish', 'fees.collect', 'fees.refund',
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
                'admissions.manage', 'attendance.correct', 'results.publish', 'notices.manage',
            ],
            'Accountant' => ['dashboard.view', 'students.view', 'guardians.view', 'fees.collect', 'fees.refund'],
            'Admission Officer' => [
                'dashboard.view', 'academics.view', 'students.view', 'students.create', 'students.update',
                'guardians.view', 'guardians.manage', 'admissions.manage', 'imports.manage',
            ],
            'Teacher' => ['dashboard.view', 'academics.view', 'attendance.mark', 'marks.enter'],
            'Class Teacher' => ['dashboard.view', 'academics.view', 'students.view', 'guardians.view', 'attendance.mark', 'attendance.correct', 'marks.enter'],
            'Librarian' => ['dashboard.view'],
            'Transport Manager' => ['dashboard.view'],
            'Receptionist' => ['dashboard.view', 'students.view', 'guardians.view', 'admissions.manage'],
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
        return ['School Admin', 'Principal', 'Accountant', 'Admission Officer', 'Teacher', 'Class Teacher', 'Librarian', 'Transport Manager', 'Receptionist'];
    }
}
