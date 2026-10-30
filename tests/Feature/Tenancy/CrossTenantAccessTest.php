<?php

namespace Tests\Feature\Tenancy;

use App\Models\School;
use App\Models\User;
use App\Modules\Setup\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant A must never read, change or delete Tenant B's records, whatever
 * IDs, parameters, headers or sessions are sent.
 */
class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Campus $betaCampus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionSchool('alpha');
        $this->beta = $this->provisionSchool('beta');

        $this->betaCampus = $this->inSchool($this->beta, fn () => Campus::create([
            'name' => 'Beta North',
            'code' => 'BN-1',
        ]));
    }

    public function test_listing_only_shows_own_school_records(): void
    {
        $this->actingAs($this->ownerOf($this->alpha))
            ->get($this->schoolUrl($this->alpha, '/app/campuses'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/campuses/index')
                ->has('campuses.data', 1)
                ->where('campuses.data.0.name', 'Main campus'));
    }

    public function test_school_id_in_query_or_header_is_ignored(): void
    {
        $this->actingAs($this->ownerOf($this->alpha))
            ->withHeader('X-School-Id', (string) $this->beta->id)
            ->get($this->schoolUrl($this->alpha, '/app/campuses?school_id='.$this->beta->id.'&search=Beta'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('campuses.data', 0));
    }

    public function test_guessed_id_from_another_school_is_not_found_for_read_update_and_delete(): void
    {
        $owner = $this->ownerOf($this->alpha);
        $url = $this->schoolUrl($this->alpha, '/app/campuses/'.$this->betaCampus->id);

        $this->actingAs($owner)->get($url)->assertNotFound();
        $this->actingAs($owner)->put($url, ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($owner)->delete($url)->assertNotFound();

        $this->assertDatabaseHas('campuses', [
            'id' => $this->betaCampus->id,
            'school_id' => $this->beta->id,
            'name' => 'Beta North',
        ]);
    }

    public function test_school_id_in_request_body_cannot_redirect_a_write(): void
    {
        $this->actingAs($this->ownerOf($this->alpha))
            ->post($this->schoolUrl($this->alpha, '/app/campuses'), [
                'name' => 'Injected',
                'code' => 'INJ',
                'school_id' => $this->beta->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('campuses', ['name' => 'Injected', 'school_id' => $this->alpha->id]);
        $this->assertDatabaseMissing('campuses', ['name' => 'Injected', 'school_id' => $this->beta->id]);
    }

    public function test_unique_rules_are_scoped_to_the_school(): void
    {
        // Beta already uses code BN-1; Alpha may use it too.
        $this->actingAs($this->ownerOf($this->alpha))
            ->post($this->schoolUrl($this->alpha, '/app/campuses'), ['name' => 'Alpha North', 'code' => 'BN-1'])
            ->assertSessionHasNoErrors();
    }

    public function test_user_authenticated_on_one_school_is_rejected_on_another(): void
    {
        // actingAs() bypasses the scoped user provider, simulating a forged or
        // replayed session for a user from another school.
        $this->actingAs($this->ownerOf($this->alpha))
            ->get($this->schoolUrl($this->beta, '/app/dashboard'))
            ->assertRedirect($this->schoolUrl($this->beta, '/login'));

        $this->assertGuest();
    }

    public function test_session_bound_to_one_school_is_reset_on_another_host(): void
    {
        $owner = $this->ownerOf($this->alpha);

        $this->actingAs($owner)->get($this->schoolUrl($this->alpha, '/app/dashboard'))->assertOk();

        // Same session (cookies persist between test requests) sent to Beta.
        $this->get($this->schoolUrl($this->beta, '/app/dashboard'))
            ->assertRedirect($this->schoolUrl($this->beta, '/login'));
    }

    public function test_same_email_in_two_schools_are_separate_accounts(): void
    {
        $this->inSchool($this->alpha, fn () => User::factory()->create(['email' => 'shared@example.com', 'password' => 'alpha-pass']));
        $this->inSchool($this->beta, fn () => User::factory()->create(['email' => 'shared@example.com', 'password' => 'beta-pass']));

        // Beta's password does not open Alpha's account.
        $this->post($this->schoolUrl($this->alpha, '/login'), ['email' => 'shared@example.com', 'password' => 'beta-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post($this->schoolUrl($this->alpha, '/login'), ['email' => 'shared@example.com', 'password' => 'alpha-pass']);
        $this->assertAuthenticated();
        $this->assertSame($this->alpha->id, auth()->user()->school_id);
    }

    public function test_owner_of_one_school_cannot_log_in_on_another(): void
    {
        $this->post($this->schoolUrl($this->beta, '/login'), [
            'email' => 'owner@alpha.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_shared_props_do_not_leak_internal_ids_or_other_schools(): void
    {
        $this->actingAs($this->ownerOf($this->alpha))
            ->get($this->schoolUrl($this->alpha, '/app/dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('school.slug', 'alpha')
                ->missing('school.id')
                ->missing('auth.user.school_id')
                ->missing('auth.user.password')
                ->where('stats.campuses', 1));
    }
}
