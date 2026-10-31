<?php

namespace App\Console\Commands;

use App\Enums\SchoolStatus;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Go-live checklist for one school (pilot onboarding). Read-only: counts and
 * flags only, no names or personal data in the output.
 *
 * FAIL = the school cannot run day-to-day work yet; WARN = works, but parents,
 * payments or the website will be incomplete.
 */
class SchoolReadiness extends Command
{
    protected $signature = 'school:readiness {slug : School subdomain}';

    protected $description = 'Check whether a school is set up well enough to go live';

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->rows = [];
        $school = School::query()->where('slug', $this->argument('slug'))->first();
        if (! $school) {
            $this->error('No school with that subdomain.');

            return self::FAILURE;
        }

        $t = fn (string $table): Builder => DB::table($table)->where("{$table}.school_id", $school->id);

        // School and access
        $this->check(in_array($school->status, [SchoolStatus::Active, SchoolStatus::Trial], true),
            'School status', $school->status->value);
        $domain = $t('school_domains')->where('is_primary', true)->value('domain');
        $this->check((bool) $domain, 'Primary domain', $domain ?: 'none');
        $missing = collect(['name_bn', 'phone', 'address', 'district'])->filter(fn ($f) => blank($school->{$f}))->values();
        $this->check($missing->isEmpty(), 'School profile', $missing->isEmpty() ? 'complete' : 'missing: '.$missing->implode(', '), warnOnly: true);

        $admins = $t('users')->whereNull('users.deleted_at')->where('users.status', 'active')
            ->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'users.id')
                ->where('mr.model_type', (new \App\Models\User)->getMorphClass())->whereColumn('mr.school_id', 'users.school_id'))
            ->join('roles', 'roles.id', '=', 'mr.role_id')
            ->whereIn('roles.name', ['School Owner', 'School Admin', 'Principal']);
        $adminCount = (clone $admins)->distinct()->count('users.id');
        $this->check($adminCount > 0, 'Active owner/admin/principal accounts', (string) $adminCount);
        $without2fa = (clone $admins)->whereNull('users.two_factor_confirmed_at')->distinct()->count('users.id');
        $this->check($without2fa === 0, 'Admins with two-factor login', ($adminCount - $without2fa)." of {$adminCount}", warnOnly: true);

        // Academic structure
        $session = $t('academic_sessions')->where('is_current', true)->first();
        $this->check((bool) $session, 'Current academic session', $session ? "{$session->name} ({$session->status})" : 'none');
        $this->check($t('classes')->count() > 0, 'Classes', (string) $t('classes')->count());
        $this->check($t('sections')->count() > 0, 'Sections', (string) $t('sections')->count());
        $this->check($t('subjects')->count() > 0, 'Subjects', (string) $t('subjects')->count());
        $this->check($t('class_subjects')->count() > 0, 'Subjects assigned to classes', (string) $t('class_subjects')->count(), warnOnly: true);
        $this->check($t('grading_scales')->where('is_default', true)->exists(), 'Default grading scale', $t('grading_scales')->where('is_default', true)->exists() ? 'yes' : 'none');

        // People
        $students = $t('students')->whereNull('deleted_at')->where('status', 'active')->count();
        $enrolled = $session ? $t('student_enrollments')->where('academic_session_id', $session->id)->where('status', 'enrolled')->count() : 0;
        $this->check($students > 0, 'Active students', (string) $students);
        $this->check($students === 0 || $enrolled >= $students, 'Students enrolled in the current session', "{$enrolled} of {$students}", warnOnly: true);

        $noGuardian = $t('students')->whereNull('students.deleted_at')->where('students.status', 'active')
            ->whereNotExists(fn ($q) => $q->from('student_guardians as sg')->whereColumn('sg.student_id', 'students.id')->whereColumn('sg.school_id', 'students.school_id'))
            ->count();
        $this->check($noGuardian === 0, 'Students with a guardian', ($students - $noGuardian)." of {$students}", warnOnly: true);
        $guardians = $t('guardians')->whereNull('deleted_at')->count();
        $noPhone = $t('guardians')->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))->count();
        $this->check($noPhone === 0, 'Guardians with a phone number (SMS)', ($guardians - $noPhone)." of {$guardians}", warnOnly: true);
        $parentAccounts = $t('guardians')->whereNull('deleted_at')->whereNotNull('user_id')->count();

        $teachers = $t('staff_members')->whereNull('deleted_at')->where('status', 'active')->where('staff_type', 'teacher')->count();
        $this->check($teachers > 0, 'Active teachers', (string) $teachers);
        $teacherLogins = $t('staff_members')->whereNull('deleted_at')->where('staff_type', 'teacher')->whereNotNull('user_id')->count();
        $this->check($teachers === 0 || $teacherLogins > 0, 'Teachers with a login', "{$teacherLogins} of {$teachers}", warnOnly: true);
        $this->check($t('teacher_assignments')->count() > 0, 'Teacher subject assignments', (string) $t('teacher_assignments')->count(), warnOnly: true);
        $this->check($parentAccounts > 0, 'Parent portal accounts', "{$parentAccounts} of {$guardians} guardians", warnOnly: true);

        // Fees, payments, messaging, website, subscription
        $structures = $session ? $t('fee_structures')->where('academic_session_id', $session->id)->where('is_active', true)->count() : 0;
        $this->check($structures > 0, 'Fee structures for the current session', (string) $structures, warnOnly: true);
        $gateway = $t('payment_gateway_accounts')->where('is_enabled', true)->first(['gateway', 'mode']);
        $this->check($gateway && $gateway->mode === 'live', 'Online payment gateway',
            $gateway ? "{$gateway->gateway} ({$gateway->mode})" : 'none — counter/bank payments only', warnOnly: true);
        $sms = $t('sms_provider_accounts')->where('is_enabled', true)->first(['provider']);
        $this->check($sms && $sms->provider !== 'log', 'SMS provider', $sms ? $sms->provider : 'none — email/in-app only', warnOnly: true);
        $site = $t('website_settings')->first(['is_published', 'logo_path']);
        $this->check((bool) ($site->is_published ?? false), 'Public website published', ($site->is_published ?? false) ? 'yes' : 'no', warnOnly: true);
        $this->check(filled($site->logo_path ?? null), 'School logo', filled($site->logo_path ?? null) ? 'yes' : 'none', warnOnly: true);
        $sub = $t('subscriptions')->whereIn('status', ['trialing', 'active', 'past_due'])->latest('id')->first(['status', 'plan_id']);
        $plan = $sub ? DB::table('plans')->where('id', $sub->plan_id)->value('code') : null;
        $this->check((bool) $sub, 'Subscription', $sub ? "{$plan} ({$sub->status})" : 'none — unmanaged, no limits', warnOnly: true);
        $failedImports = $t('data_import_jobs')->where('status', 'failed')->count();
        $this->check($failedImports === 0, 'Failed imports', (string) $failedImports, warnOnly: true);

        $this->table(['', 'Check', 'Detail'], $this->rows);

        $fails = count(array_filter($this->rows, fn ($r) => $r[0] === 'FAIL'));
        $warns = count(array_filter($this->rows, fn ($r) => $r[0] === 'WARN'));
        $this->line(sprintf('%s (%s): %d required item(s) missing, %d warning(s).', $school->name, $school->url(), $fails, $warns));

        return $fails === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $ok, string $label, string $detail, bool $warnOnly = false): void
    {
        $this->rows[] = [$ok ? 'ok' : ($warnOnly ? 'WARN' : 'FAIL'), $label, $detail];
    }
}
