<?php

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Milestone 8 isolation review, as executable checks over the whole schema
 * and route table. A new table, model or route that is not classified here
 * fails the build until someone decides how it is isolated.
 */
class IsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Platform-owned tables that reference a school but are not tenant-scoped (read with an explicit school id). */
    private const CENTRAL_WITH_SCHOOL_ID = [
        'platform_activity_logs', 'platform_invoices', 'platform_payments', 'school_domains', 'school_registrations',
        'subscriptions', 'support_access_grants', 'support_tickets',
        // Spatie Permission tables use school_id as the team key (scoped by the package).
        'roles', 'model_has_roles', 'model_has_permissions',
        // Password reset tokens are keyed by (school_id, email) by TenantPasswordTokenRepository.
        'password_reset_tokens',
    ];

    /** Tables without school_id, each for a reason. */
    private const NO_SCHOOL_ID = [
        'schools', 'platform_users', 'platform_password_reset_tokens', 'plans', 'plan_features', 'support_ticket_messages', // central
        'permissions', 'role_has_permissions', // global permission names; role links are team-scoped through roles
        'notifications', // in-app notifications: only ever read through the signed-in notifiable
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'migrations', // framework
    ];

    /** @return array<string, bool> table => has school_id */
    private function tables(): array
    {
        $rows = DB::select("SELECT t.table_name AS name, SUM(c.column_name = 'school_id') AS scoped FROM information_schema.tables t
            JOIN information_schema.columns c ON c.table_schema = t.table_schema AND c.table_name = t.table_name
            WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE' GROUP BY t.table_name");

        return collect($rows)->mapWithKeys(fn ($r) => [$r->name => (bool) $r->scoped])->all();
    }

    /** @return list<class-string<Model>> */
    private function models(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function test_every_table_is_classified(): void
    {
        $unclassified = [];

        foreach ($this->tables() as $table => $scoped) {
            if (! $scoped && ! in_array($table, self::NO_SCHOOL_ID, true)) {
                $unclassified[] = $table.' (no school_id)';
            }
        }

        $this->assertSame([], $unclassified, 'Decide how these tables are isolated and list them in IsolationAuditTest.');
    }

    public function test_tenant_tables_have_school_foreign_key_and_composite_key(): void
    {
        $fks = collect(DB::select("SELECT table_name AS t FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND column_name = 'school_id' AND referenced_table_name = 'schools'"))->pluck('t')->unique()->all();
        $composite = collect(DB::select("SELECT table_name AS t, index_name AS i, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
            FROM information_schema.statistics WHERE table_schema = DATABASE() AND non_unique = 0 GROUP BY table_name, index_name"))
            ->filter(fn ($r) => $r->cols === 'school_id,id')->pluck('t')->all();

        $problems = [];

        foreach ($this->tables() as $table => $scoped) {
            if (! $scoped || in_array($table, self::CENTRAL_WITH_SCHOOL_ID, true)) {
                continue;
            }

            if (! in_array($table, $fks, true)) {
                $problems[] = "{$table}: school_id has no foreign key to schools";
            }

            if (! in_array($table, $composite, true) && $table !== 'school_settings' && $table !== 'tenant_sequences') {
                $problems[] = "{$table}: no unique (school_id, id) key for composite child foreign keys";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_every_model_on_a_tenant_table_uses_the_tenant_scope(): void
    {
        $tables = $this->tables();
        $problems = [];

        foreach ($this->models() as $class) {
            $table = (new $class)->getTable();
            $scoped = $tables[$table] ?? false;
            $usesTrait = in_array(BelongsToSchool::class, class_uses_recursive($class), true);

            if ($scoped && ! in_array($table, self::CENTRAL_WITH_SCHOOL_ID, true) && ! $usesTrait) {
                $problems[] = "{$class} ({$table}) does not use BelongsToSchool";
            }

            if ($usesTrait && ! $scoped) {
                $problems[] = "{$class} uses BelongsToSchool but {$table} has no school_id";
            }

            if ($usesTrait && in_array('school_id', (new $class)->getFillable(), true)) {
                $problems[] = "{$class} lists school_id as fillable";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_school_hosts_routes_are_authenticated_except_the_public_ones(): void
    {
        $public = ['/', 'about', 'academics', 'teachers', 'notices', 'notices/{notice}', 'notices/{notice}/attachment', 'events', 'gallery', 'gallery/{album}',
            'contact', 'p/{slug}', 'media/{path}', 'sitemap.xml', 'robots.txt', 'admission', 'payments/callback/{gateway}', 'payments/return/{gateway}',
            'invitation/{token}', 'up', 'storage/{path}'];
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $mw = $route->gatherMiddleware();
            $isTenant = in_array('tenant', $mw, true);

            if (str_starts_with($uri, 'platform/')) {
                if ($uri !== 'platform/login' && ! in_array('auth:platform', $mw, true)) {
                    $problems[] = "platform route {$uri} without auth:platform";
                }

                if (! in_array('platform.host', $mw, true)) {
                    $problems[] = "platform route {$uri} without platform.host";
                }

                continue;
            }

            if (! $isTenant || in_array($uri, $public, true)) {
                continue;
            }

            $guestOnly = collect($mw)->contains(fn ($m) => str_starts_with($m, 'guest'));
            $fortifyGuest = in_array($uri, ['login', 'forgot-password', 'reset-password', 'reset-password/{token}', 'two-factor-challenge', 'register', 'user/confirm-password', 'user/confirmed-password-status'], true);

            if (! $guestOnly && ! $fortifyGuest && ! collect($mw)->contains(fn ($m) => $m === 'auth:web' || $m === 'auth' || str_starts_with($m, 'auth:'))) {
                $problems[] = "{$uri} is not behind authentication";
            }

            if (str_starts_with($uri, 'app/') && ! in_array('staff', $mw, true)) {
                $problems[] = "{$uri} misses the staff-area middleware";
            }

            foreach (['parent' => 'portal:parent', 'student' => 'portal:student', 'teacher' => 'portal:teacher'] as $prefix => $gate) {
                if (str_starts_with($uri, $prefix.'/') || $uri === $prefix) {
                    if (! in_array($gate, $mw, true)) {
                        $problems[] = "{$uri} misses {$gate}";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
