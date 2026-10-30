<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Models\School;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\SchoolLifecycle;
use App\Support\Tenancy\TenantCache;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Queue, cache, storage, password reset and audit isolation.
 */
class TenantInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionSchool('alpha');
        $this->beta = $this->provisionSchool('beta');
        RecordTenantJob::$seen = [];
    }

    public function test_queued_job_restores_the_dispatching_school_and_clears_afterwards(): void
    {
        config(['queue.default' => 'database']);

        $this->inSchool($this->alpha, function () {
            RecordTenantJob::dispatch();
        });
        $this->inSchool($this->beta, function () {
            RecordTenantJob::dispatch();
        });

        $payload = json_decode(DB::table('jobs')->orderBy('id')->value('payload'), true);
        $this->assertSame($this->alpha->id, $payload['school_id']);

        $this->tenant()->clear();
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);

        $this->assertSame([
            ['school' => $this->alpha->id, 'users' => 1],
            ['school' => $this->beta->id, 'users' => 1],
        ], RecordTenantJob::$seen);
        $this->assertFalse($this->tenant()->check(), 'Tenant context leaked out of the worker.');
    }

    public function test_job_without_tenant_runs_without_context(): void
    {
        config(['queue.default' => 'database']);
        $this->tenant()->clear();

        RecordTenantJob::dispatch();
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);

        $this->assertSame([['school' => null, 'users' => 0]], RecordTenantJob::$seen);
    }

    public function test_sync_job_inside_a_request_restores_the_request_context(): void
    {
        config(['queue.default' => 'sync']);

        $this->inSchool($this->alpha, function () {
            RecordTenantJob::dispatch();
            $this->assertSame($this->alpha->id, $this->tenant()->id());
        });
    }

    public function test_jobs_for_suspended_schools_are_skipped(): void
    {
        config(['queue.default' => 'database']);

        // Statement form: PendingDispatch sends on destruct, i.e. at the end of the statement.
        $this->inSchool($this->alpha, function () {
            GuardedTenantJob::dispatch();
        });
        School::query()->whereKey($this->alpha->id)->update(['status' => 'suspended']);

        $this->tenant()->clear();
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);

        $this->assertSame([], RecordTenantJob::$seen);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_cache_keys_are_namespaced_per_school(): void
    {
        $this->inSchool($this->alpha, fn () => app(TenantCache::class)->put('stats', 'alpha-value'));
        $this->inSchool($this->beta, fn () => app(TenantCache::class)->put('stats', 'beta-value'));

        $this->assertSame('alpha-value', $this->inSchool($this->alpha, fn () => app(TenantCache::class)->get('stats')));
        $this->assertSame('beta-value', $this->inSchool($this->beta, fn () => app(TenantCache::class)->get('stats')));
        $this->assertNull(Cache::get('stats'));
        $this->assertSame('school:'.$this->alpha->id.':stats', $this->inSchool($this->alpha, fn () => app(TenantCache::class)->key('stats')));
    }

    public function test_storage_paths_are_partitioned_and_traversal_is_rejected(): void
    {
        $this->inSchool($this->alpha, function () {
            $storage = app(TenantStorage::class);

            $this->assertSame('schools/'.$this->alpha->id.'/documents/a.pdf', $storage->path('documents/a.pdf'));
            $this->assertTrue($storage->owns('schools/'.$this->alpha->id.'/documents/a.pdf'));
            $this->assertFalse($storage->owns('schools/'.$this->beta->id.'/documents/a.pdf'));
            $this->assertFalse($storage->owns('schools/'.$this->alpha->id.'/../'.$this->beta->id.'/a.pdf'));

            $this->expectException(InvalidArgumentException::class);
            $storage->path('../'.$this->beta->id.'/secret.pdf');
        });
    }

    public function test_password_reset_token_from_one_school_cannot_reset_another(): void
    {
        $this->inSchool($this->alpha, fn () => User::factory()->create(['email' => 'shared@example.com']));
        $this->inSchool($this->beta, fn () => User::factory()->create(['email' => 'shared@example.com', 'password' => 'beta-original']));

        $token = $this->inSchool($this->alpha, fn () => app('auth.password')->broker()->createToken(
            User::query()->where('email', 'shared@example.com')->firstOrFail(),
        ));

        $this->post($this->schoolUrl($this->beta, '/reset-password'), [
            'token' => $token,
            'email' => 'shared@example.com',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertSessionHasErrors('email');

        $this->post($this->schoolUrl($this->beta, '/login'), ['email' => 'shared@example.com', 'password' => 'beta-original']);
        $this->assertAuthenticated();

        $this->assertDatabaseHas('password_reset_tokens', ['school_id' => $this->alpha->id, 'email' => 'shared@example.com']);
        $this->assertDatabaseMissing('password_reset_tokens', ['school_id' => $this->beta->id]);
    }

    public function test_audit_logger_redacts_sensitive_values(): void
    {
        $redacted = AuditLogger::redact([
            'name' => 'Rahim',
            'password' => 'secret',
            'guardian' => ['national_id' => '1990123456789', 'phone' => '017'],
            'Birth_Registration_No' => '2010',
            'gateway' => ['store_password' => 'x', 'signature' => 'y'],
        ]);

        $this->assertSame('Rahim', $redacted['name']);
        $this->assertSame('[redacted]', $redacted['password']);
        $this->assertSame('[redacted]', $redacted['guardian']['national_id']);
        $this->assertSame('017', $redacted['guardian']['phone']);
        $this->assertSame('[redacted]', $redacted['Birth_Registration_No']);
        $this->assertSame('[redacted]', $redacted['gateway']['store_password']);
        $this->assertSame('[redacted]', $redacted['gateway']['signature']);
    }

    public function test_campus_changes_are_audited_in_the_right_school(): void
    {
        $owner = $this->ownerOf($this->alpha);

        $this->actingAs($owner)
            ->post($this->schoolUrl($this->alpha, '/app/campuses'), ['name' => 'Uttara', 'code' => 'UTR'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'school_id' => $this->alpha->id,
            'user_id' => $owner->id,
            'action' => 'campus.created',
        ]);
        $this->assertDatabaseMissing('activity_logs', ['school_id' => $this->beta->id, 'action' => 'campus.created']);
    }

    public function test_suspension_keeps_data_and_blocks_access(): void
    {
        app(SchoolLifecycle::class)->transition($this->alpha, \App\Enums\SchoolStatus::Suspended, 'Unpaid');

        $this->get($this->schoolUrl($this->alpha, '/login'))->assertStatus(503);
        $this->get($this->schoolUrl($this->beta, '/login'))->assertOk();
        $this->assertSame(1, User::withoutTenancy()->where('school_id', $this->alpha->id)->count());
        $this->assertDatabaseHas('platform_activity_logs', ['school_id' => $this->alpha->id, 'action' => 'school.status_changed']);
    }
}

class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array{school: int|null, users: int}> */
    public static array $seen = [];

    public function handle(TenantContext $tenant): void
    {
        self::$seen[] = ['school' => $tenant->id(), 'users' => User::query()->count()];
    }
}

class GuardedTenantJob extends RecordTenantJob
{
    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureTenantIsAccessible];
    }
}
