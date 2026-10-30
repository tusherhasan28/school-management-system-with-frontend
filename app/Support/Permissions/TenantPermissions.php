<?php

namespace App\Support\Permissions;

/**
 * Catalogue of tenant permissions and the default roles a new school gets.
 * Schools may customise roles later; permission names are global.
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
            // Declared now so roles are stable; enforced as modules land.
            'students.view', 'students.create', 'students.update', 'students.export',
            'admissions.manage', 'attendance.mark', 'attendance.correct',
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
            'Principal' => ['dashboard.view', 'campuses.view', 'audit.view', 'students.view', 'students.export', 'attendance.correct', 'results.publish', 'notices.manage'],
            'Accountant' => ['dashboard.view', 'students.view', 'fees.collect', 'fees.refund'],
            'Admission Officer' => ['dashboard.view', 'students.view', 'students.create', 'students.update', 'admissions.manage'],
            'Teacher' => ['dashboard.view', 'attendance.mark', 'marks.enter'],
            'Class Teacher' => ['dashboard.view', 'students.view', 'attendance.mark', 'attendance.correct', 'marks.enter'],
            'Librarian' => ['dashboard.view'],
            'Transport Manager' => ['dashboard.view'],
            'Receptionist' => ['dashboard.view', 'students.view', 'admissions.manage'],
            'Student' => [],
            'Parent' => [],
        ];
    }
}
