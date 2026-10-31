<?php

namespace Tests\Feature\Ops;

use App\Support\Ops\DatabaseManifest;
use App\Support\Ops\HealthChecks;
use App\Support\Ops\OpsAlertMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OpsHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ops.alert_email' => 'ops@example.test',
            'ops.checks.disk_min_free_percent' => 0,
            'ops.checks.horizon' => false,
            'ops.checks.scheduler' => false,
            'ops.checks.backup' => false,
        ]);
        Cache::flush();
    }

    private function failJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(), 'connection' => 'redis', 'queue' => 'messages',
            'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
        ]);
    }

    public function test_healthy_system_passes_without_alerts(): void
    {
        Mail::fake();

        $this->artisan('ops:health --alert')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_failed_job_alerts_once_per_cooldown_then_reports_recovery(): void
    {
        Mail::fake();
        $this->failJob();

        $this->artisan('ops:health --alert')->assertFailed();
        $this->artisan('ops:health --alert')->assertFailed();

        Mail::assertSent(OpsAlertMail::class, 1);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m) => array_keys($m->failing) === ['failed_jobs']
            && $m->hasTo('ops@example.test'));

        $this->travel(20)->minutes(); // outside the 15-minute window
        $this->artisan('ops:health --alert')->assertSuccessful();

        Mail::assertSent(OpsAlertMail::class, 2);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m) => $m->failing === [] && $m->recovered === ['failed_jobs']);
    }

    public function test_scheduler_and_backup_checks(): void
    {
        config(['ops.checks.scheduler' => true, 'ops.checks.backup' => true,
            'ops.checks.backup_status_file' => $file = sys_get_temp_dir().'/ops-test-last-backup-'.getmypid()]);
        @unlink($file);

        $results = app(HealthChecks::class)->run();
        $this->assertFalse($results['scheduler']['ok']);
        $this->assertFalse($results['backup']['ok']);

        HealthChecks::beat();
        touch($file);
        $results = app(HealthChecks::class)->run();
        $this->assertTrue($results['scheduler']['ok']);
        $this->assertTrue($results['backup']['ok']);

        touch($file, now()->subHours(30)->getTimestamp());
        $this->travel(11)->minutes();
        $results = app(HealthChecks::class)->run();
        $this->assertFalse($results['scheduler']['ok']);
        $this->assertFalse($results['backup']['ok']);
        @unlink($file);
    }

    public function test_database_queue_worker_check_catches_a_stopped_worker_cron(): void
    {
        config(['queue.default' => 'database']);
        $this->assertTrue(app(HealthChecks::class)->run()['queue_worker']['ok']);

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->subMinutes(2)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $this->assertTrue(app(HealthChecks::class)->run()['queue_worker']['ok']);

        $this->travel(15)->minutes();
        $result = app(HealthChecks::class)->run()['queue_worker'];
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('queue cron', $result['detail']);
    }

    public function test_health_endpoint_requires_the_token_and_reports_failures(): void
    {
        $url = 'http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.domain').'/ops/health';

        config(['ops.health_token' => null]);
        $this->getJson($url.'?token=')->assertNotFound();

        config(['ops.health_token' => 'secret-token-123']);
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'?token=wrong')->assertNotFound();

        $this->getJson($url, ['Authorization' => 'Bearer secret-token-123'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('checks.database.ok', true)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->failJob();
        $this->getJson($url.'?token=secret-token-123')->assertStatus(503)->assertJsonPath('checks.failed_jobs.ok', false);

        // Not exposed on school sites.
        $this->getJson('http://'.config('tenancy.domain').'/ops/health?token=secret-token-123')->assertNotFound();
    }

    public function test_manifest_verification_detects_differences(): void
    {
        $manifest = DatabaseManifest::build(DB::connection());
        $this->assertArrayHasKey('schools', $manifest['tables']);
        $this->assertSame([], DatabaseManifest::compare($manifest, DatabaseManifest::build(DB::connection())));

        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, json_encode($manifest));
        $this->artisan('ops:verify-restore', ['manifest' => $path])->assertSuccessful();

        $this->failJob();
        $this->artisan('ops:verify-restore', ['manifest' => $path])
            ->expectsOutputToContain('failed_jobs: 1 rows, expected 0')->assertFailed();
        $this->artisan('ops:verify-restore', ['manifest' => $path, '--ignore' => ['failed_jobs']])->assertSuccessful();
        @unlink($path);

        $this->expectException(\InvalidArgumentException::class);
        DatabaseManifest::connection('bad-name; drop');
    }

    public function test_preflight_reports_unsafe_production_settings(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://example.com', 'session.secure' => false]);

        $this->artisan('ops:preflight')
            ->expectsOutputToContain('must be false on a live server')
            ->assertFailed();

        config(['app.debug' => false, 'app.url' => 'https://example.com.bd', 'tenancy.domain' => 'example.com.bd',
            'session.secure' => true, 'cache.default' => 'array', 'queue.default' => 'database', 'session.driver' => 'array']);
        @mkdir(public_path('build'), 0775, true);
        $manifest = public_path('build/manifest.json');
        $created = ! is_file($manifest) && file_put_contents($manifest, '{}');

        $this->artisan('ops:preflight')->assertSuccessful();

        if ($created) {
            unlink($manifest);
        }
    }
}
