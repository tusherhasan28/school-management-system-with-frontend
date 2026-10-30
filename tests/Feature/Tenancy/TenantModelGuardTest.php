<?php

namespace Tests\Feature\Tenancy;

use App\Models\School;
use App\Models\User;
use App\Modules\Setup\Models\Campus;
use App\Support\Tenancy\Exceptions\CrossTenantWriteException;
use App\Support\Tenancy\Exceptions\TenantContextException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Model and database layers of tenant isolation, independent of HTTP.
 */
class TenantModelGuardTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionSchool('alpha');
        $this->beta = $this->provisionSchool('beta');
    }

    public function test_scope_fails_closed_without_tenant_context(): void
    {
        $this->tenant()->clear();

        $this->assertSame(0, Campus::query()->count());
        $this->assertNull(User::query()->first());
        $this->assertSame(2, Campus::withoutTenancy()->count());
    }

    public function test_queries_only_see_the_current_school(): void
    {
        $this->inSchool($this->alpha, function () {
            $this->assertSame(['Main campus'], Campus::query()->pluck('name')->all());
            $this->assertSame([$this->alpha->id], Campus::query()->pluck('school_id')->unique()->values()->all());
        });
    }

    public function test_creating_without_tenant_context_is_refused(): void
    {
        $this->tenant()->clear();

        $this->expectException(TenantContextException::class);

        Campus::create(['name' => 'Orphan']);
    }

    public function test_school_id_is_not_mass_assignable(): void
    {
        // Outside production the attempt throws; in production it is silently dropped.
        try {
            $this->inSchool($this->alpha, fn () => Campus::create(['name' => 'X', 'school_id' => $this->beta->id]));
            $this->fail('school_id was mass assigned.');
        } catch (MassAssignmentException) {
            // expected
        }

        Model::preventSilentlyDiscardingAttributes(false);
        $campus = $this->inSchool($this->alpha, fn () => Campus::create(['name' => 'Y', 'school_id' => $this->beta->id]));
        Model::preventSilentlyDiscardingAttributes(true);

        $this->assertSame($this->alpha->id, $campus->school_id);
        $this->assertSame(0, Campus::withoutTenancy()->where('school_id', $this->beta->id)->whereIn('name', ['X', 'Y'])->count());
    }

    public function test_saving_another_schools_row_is_refused(): void
    {
        $betaCampus = $this->inSchool($this->beta, fn () => Campus::query()->firstOrFail());

        $this->expectException(CrossTenantWriteException::class);

        $this->inSchool($this->alpha, function () use ($betaCampus) {
            $betaCampus->name = 'Changed from Alpha';
            $betaCampus->save();
        });
    }

    public function test_deleting_another_schools_row_is_refused(): void
    {
        $betaCampus = $this->inSchool($this->beta, fn () => Campus::query()->firstOrFail());

        $this->expectException(CrossTenantWriteException::class);

        $this->inSchool($this->alpha, fn () => $betaCampus->delete());
    }

    public function test_a_row_cannot_be_moved_to_another_school(): void
    {
        $this->expectException(CrossTenantWriteException::class);

        $this->inSchool($this->alpha, function () {
            $campus = Campus::query()->firstOrFail();
            $campus->forceFill(['school_id' => $this->beta->id])->save();
        });
    }

    public function test_tenant_context_is_immutable_within_a_lifecycle(): void
    {
        $this->expectException(TenantContextException::class);

        $this->inSchool($this->alpha, fn () => $this->tenant()->set($this->beta));
    }

    public function test_run_restores_the_previous_context(): void
    {
        $this->tenant()->set($this->alpha);

        $this->inSchool($this->beta, fn () => $this->assertSame($this->beta->id, $this->tenant()->id()));

        $this->assertSame($this->alpha->id, $this->tenant()->id());
        $this->assertSame($this->alpha->id, app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_database_rejects_cross_school_references_with_composite_foreign_keys(): void
    {
        $betaUserId = $this->ownerOf($this->beta)->id;

        // Bypass every application layer: the FK (school_id, user_id) → users(school_id, id) must still hold.
        $this->expectException(QueryException::class);

        DB::table('activity_logs')->insert([
            'school_id' => $this->alpha->id,
            'user_id' => $betaUserId,
            'action' => 'forged',
            'created_at' => now(),
        ]);
    }

    public function test_composite_foreign_key_allows_same_school_reference(): void
    {
        DB::table('activity_logs')->insert([
            'school_id' => $this->alpha->id,
            'user_id' => $this->ownerOf($this->alpha)->id,
            'action' => 'legit',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'legit']);
    }

    public function test_roles_are_scoped_per_school(): void
    {
        $alphaOwner = $this->ownerOf($this->alpha);

        $this->inSchool($this->alpha, fn () => $this->assertTrue($alphaOwner->fresh()->hasRole('School Owner')));

        // The same user id evaluated under Beta's team has no roles there.
        $this->inSchool($this->beta, function () use ($alphaOwner) {
            $user = User::withoutTenancy()->findOrFail($alphaOwner->id);
            $this->assertFalse($user->hasRole('School Owner'));
            $this->assertFalse($user->can('campuses.manage'));
        });
    }
}
