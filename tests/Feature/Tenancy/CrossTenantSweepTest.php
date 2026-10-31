<?php

namespace Tests\Feature\Tenancy;

use App\Models\School;
use App\Models\User;
use App\Modules\Portal\Services\PortalAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFullTenantDataset;
use Tests\TestCase;

/**
 * Milestone 8: every route on a school host that takes an id is called by
 * school A's most privileged users with the ids of school B's records, for
 * every HTTP method. Each call must be refused with 404/403 (never a
 * validation error that would confirm the record exists), and school B's
 * data must be unchanged afterwards.
 */
class CrossTenantSweepTest extends TestCase
{
    use CreatesFullTenantDataset, RefreshDatabase;

    /** Routes whose parameters are not record ids of another school. */
    private const SKIP = [
        'payments/callback/{gateway}', 'payments/return/{gateway}', 'app/fees/gateways/{gateway}', // provider keys, per school
        'reset-password/{token}', 'invitation/{token}', 'email/verify/{id}/{hash}', 'storage/{path}',
        'app/fees/reports/{report}/export', 'app/website/images/{slot}', // fixed names, own school only
    ];

    private function snapshot(int $schoolId): array
    {
        $tables = collect(DB::select("SELECT table_name AS t, SUM(column_name = 'updated_at') AS u FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name IN (SELECT table_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'school_id')
            GROUP BY table_name"));

        return $tables->mapWithKeys(fn ($r) => [$r->t => (array) DB::selectOne(
            'SELECT COUNT(*) AS n'.($r->u ? ', MAX(updated_at) AS u' : '')." FROM `{$r->t}` WHERE school_id = ?", [$schoolId]
        )])->all();
    }

    public function test_school_a_cannot_reach_school_b_records_through_any_route(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Dhaka')); // a Thursday inside the 2026 session
        $alpha = $this->provisionSchool('alpha');
        $beta = $this->provisionSchool('beta');
        $a = $this->fullDataset($alpha);
        $b = $this->fullDataset($beta);

        // School A's users: owner (every permission), a teacher, a parent and a student of A's own child.
        [$owner, $teacher, $parent, $student] = $this->inSchool($alpha, function () use ($a) {
            $owner = User::query()->findOrFail($a['owner']);
            $teacher = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Teacher'))->firstOrFail();
            $accounts = app(PortalAccounts::class);
            $child = \App\Modules\People\Models\Student::query()->findOrFail($a['student']);
            $guardian = $child->guardians()->firstOrFail();
            $guardian->update(['email' => 'parent@alpha.test']);
            $parent = $accounts->inviteGuardian($guardian->fresh());
            $child->update(['email' => 'kid@alpha.test']);
            $kid = $accounts->inviteStudent($child->fresh());
            foreach ([$parent, $kid, $teacher] as $u) {
                $u->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
            }

            return [$owner, $teacher->fresh(), $parent->fresh(), $kid->fresh()];
        });

        $before = $this->snapshot($beta->id);
        $this->onSchool($alpha);
        $accepted = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! in_array('tenant', $route->gatherMiddleware(), true) || array_diff($route->parameterNames(), ['tab']) === [] || in_array($uri, self::SKIP, true)) {
                continue;
            }

            $actor = match (true) {
                str_starts_with($uri, 'parent/') => $parent,
                str_starts_with($uri, 'student/') => $student,
                str_starts_with($uri, 'teacher/') => $teacher,
                str_starts_with($uri, 'app/') => $owner,
                default => null, // public website
            };

            // For portal routes, the child in the URL is A's own child, so only the other ids are foreign.
            $variants = str_starts_with($uri, 'parent/') ? [['student' => $a['student']], ['student' => $b['student']]] : [[]];

            foreach ($variants as $override) {
                $params = [];

                foreach ($route->parameterNames() as $name) {
                    $key = match (true) {
                        $name === 'subject' && str_contains($uri, 'exam-subjects') => 'exam_subject',
                        $name === 'assignment' && str_contains($uri, 'fees/assignments') => 'fee_assignment',
                        default => $name,
                    };
                    $params[$name] = $override[$name] ?? ($name === 'tab' ? 'fees' : ($b[$key] ?? throw new \RuntimeException("No school-B value for {{$name}} in {$uri}")));
                }

                // Nothing foreign left in this variant (A's child + a tab only): skip.
                if ($override !== [] && array_diff_key($params, ['student' => 1, 'tab' => 1]) === [] && $params['student'] === $a['student']) {
                    continue;
                }

                $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => rawurlencode((string) $params[$m[1]]), $uri);

                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $this->app['auth']->forgetGuards();
                    $request = $actor ? $this->actingAs($actor) : $this;
                    $response = $request->call($method, $url, ['_token' => csrf_token()]);
                    $status = $response->getStatusCode();
                    $checked++;

                    // Refused outright: no validation message, redirect or page that could hint the record exists.
                    if (! in_array($status, [403, 404], true)) {
                        $accepted[] = "{$method} {$url} → {$status}";
                    }
                }
            }
        }

        $this->assertGreaterThan(120, $checked, 'the sweep should cover every id-taking route');
        $this->assertSame([], $accepted, 'These requests reached another school\'s records');
        $this->assertEquals($before, $this->snapshot($beta->id), 'School B data changed during the sweep');
    }
}
