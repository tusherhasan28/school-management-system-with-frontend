<?php

namespace Tests\Feature\Support;

use App\Enums\PlatformRole;
use App\Models\ActivityLog;
use App\Models\PlatformActivityLog;
use App\Models\PlatformUser;
use App\Models\School;
use App\Models\User;
use App\Modules\Support\Models\SupportAccessGrant;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tickets between schools and the platform, and support access that is
 * requested with a reason, approved by the school, time-limited and audited.
 *
 * Platform-host and school-host requests are kept in separate tests (one
 * test app keeps the last guard as default).
 */
class SupportTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    private PlatformUser $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionSchool('alpha');
        $this->beta = $this->provisionSchool('beta');
        $this->agent = PlatformUser::factory()->role(PlatformRole::SupportAgent)->create(['name' => 'Agent Rina']);
    }

    private function owner(School $school): User
    {
        return $this->ownerOf($school);
    }

    private function ticket(School $school, string $subject = 'Cannot print report cards'): SupportTicket
    {
        return $this->inSchool($school, fn () => app(SupportService::class)->open($school, $this->owner($school), [
            'subject' => $subject, 'category' => 'technical', 'priority' => 'high', 'message' => 'The print button does nothing.',
        ]));
    }

    public function test_school_opens_and_follows_its_own_tickets_only(): void
    {
        $other = $this->ticket($this->beta, 'Beta secret ticket');
        $this->onSchool($this->alpha);
        $owner = $this->owner($this->alpha);

        $this->actingAs($owner)->post(route('support.store'), ['subject' => 'Help', 'category' => 'billing', 'priority' => 'normal', 'message' => 'Invoice question'])
            ->assertRedirect();
        $mine = SupportTicket::query()->where('school_id', $this->alpha->id)->sole();
        $this->assertSame('SUP-'.str_pad((string) $mine->id, 5, '0', STR_PAD_LEFT), $mine->reference);

        app(SupportService::class)->replyFromPlatform($mine, $this->agent, 'Checking now.', false);
        app(SupportService::class)->replyFromPlatform($mine, $this->agent, 'Looks like a template bug', true);
        $this->assertSame('waiting', $mine->fresh()->status);

        $this->actingAs($owner)->get(route('support.show', $mine->id))->assertOk()->assertInertia(fn ($p) => $p
            ->has('messages', 2)->where('messages.1.from', 'platform')->where('messages.1.message', 'Checking now.'));
        $this->assertSame(1, $owner->unreadNotifications()->count()); // only the public reply notifies
        $this->actingAs($owner)->getJson(route('notifications.index'))->assertOk()->assertJsonPath('items.0.url', '/app/support/'.$mine->id);

        $this->actingAs($owner)->post(route('support.reply', $mine->id), ['message' => 'Thanks'])->assertSessionHasNoErrors();
        $this->assertSame('open', $mine->fresh()->status);

        $this->actingAs($owner)->get(route('support.show', $other->id))->assertNotFound();
        $this->actingAs($owner)->post(route('support.reply', $other->id), ['message' => 'x'])->assertNotFound();
        $this->actingAs($owner)->get(route('support.index'))->assertInertia(fn ($p) => $p->has('tickets', 1)->where('tickets.0.id', $mine->id));

        $teacher = $this->inSchool($this->alpha, function () {
            $u = User::factory()->create();
            $u->assignRole('Teacher');

            return $u;
        });
        $this->actingAs($teacher)->get(route('support.index'))->assertForbidden();
    }

    public function test_school_approves_rejects_and_revokes_access(): void
    {
        $support = app(SupportService::class);
        $grant = $support->requestAccess($this->agent, $this->alpha, 'Report cards fail to print for Class 6', 4);
        $other = $support->requestAccess($this->agent, $this->beta, 'Beta issue that alpha must not see', 2);
        $this->onSchool($this->alpha);
        $owner = $this->owner($this->alpha);

        $this->actingAs($owner)->get(route('support.index'))->assertInertia(fn ($p) => $p->has('grants', 1)->where('grants.0.status', 'requested')->where('grants.0.agent', 'Agent Rina'));
        $this->actingAs($owner)->post(route('support.access.decide', $other->id), ['approve' => true])->assertNotFound();

        $this->travelTo(now()->startOfMinute());
        $this->actingAs($owner)->post(route('support.access.decide', $grant->id), ['approve' => true])->assertSessionHasNoErrors();
        $grant->refresh();
        $this->assertTrue($grant->isActive());
        $this->assertSame(now()->addHours(4)->toDateTimeString(), $grant->expires_at->toDateTimeString());
        $this->actingAs($owner)->post(route('support.access.decide', $grant->id), ['approve' => false])->assertSessionHasErrors('grant');

        $this->actingAs($owner)->post(route('support.access.revoke', $grant->id))->assertSessionHasNoErrors();
        $this->assertFalse($grant->fresh()->isActive());
        $this->assertSame(3, ActivityLog::query()->whereIn('action', ['support.access_approved', 'support.access_revoked'])->count() + 1);
    }

    public function test_agent_sees_snapshot_only_with_an_active_approved_grant_and_every_view_is_audited(): void
    {
        $support = app(SupportService::class);
        $grant = $support->requestAccess($this->agent, $this->alpha, 'Users cannot log in after password reset', 2);
        $snapshotUrl = $this->platformUrl('/platform/support-access/'.$grant->id);

        $this->actingAs($this->agent, 'platform')->get($snapshotUrl)->assertForbidden(); // not approved yet

        $this->inSchool($this->alpha, fn () => $support->decide($grant, $this->owner($this->alpha), true));
        $this->actingAs($this->agent, 'platform')->get($snapshotUrl)->assertOk()->assertInertia(fn ($p) => $p
            ->component('platform/support/snapshot')->has('users')->where('users.0.email', 'owner@alpha.test')->has('counts'));
        $this->assertSame(1, $grant->fresh()->view_count);
        $this->assertTrue(PlatformActivityLog::query()->where('action', 'support.snapshot_viewed')->where('school_id', $this->alpha->id)->exists());
        $this->assertTrue($this->inSchool($this->alpha, fn () => ActivityLog::query()->where('action', 'support.snapshot_viewed')->exists()));

        // Another agent cannot use it, and it stops by itself.
        $colleague = PlatformUser::factory()->role(PlatformRole::SupportAgent)->create();
        $this->actingAs($colleague, 'platform')->get($snapshotUrl)->assertForbidden();
        $this->travel(3)->hours();
        $this->actingAs($this->agent, 'platform')->get($snapshotUrl)->assertForbidden();
        $this->assertSame('expired', $grant->fresh()->effectiveStatus());
    }

    public function test_platform_screens_and_limits_on_requests(): void
    {
        $ticket = $this->ticket($this->alpha);
        $this->actingAs($this->agent, 'platform')->get($this->platformUrl('/platform/support'))->assertOk()->assertInertia(fn ($p) => $p->has('tickets.data', 1));
        $this->actingAs($this->agent, 'platform')->get($this->platformUrl('/platform/support/'.$ticket->id))->assertOk()->assertInertia(fn ($p) => $p->has('messages', 1));
        $this->actingAs($this->agent, 'platform')->post($this->platformUrl('/platform/support/'.$ticket->id.'/status'), ['status' => 'in_progress', 'assign_to_me' => true])->assertSessionHasNoErrors();
        $this->assertSame($this->agent->id, $ticket->fresh()->assigned_platform_user_id);

        $url = $this->platformUrl('/platform/schools/'.$this->alpha->id.'/support-access');
        $this->actingAs($this->agent, 'platform')->post($url, ['reason' => 'short', 'duration_hours' => 2])->assertSessionHasErrors('reason');
        $this->actingAs($this->agent, 'platform')->post($url, ['reason' => 'Investigating login failures', 'duration_hours' => 72])->assertSessionHasErrors('duration_hours');
        $betaTicket = $this->ticket($this->beta);
        $this->actingAs($this->agent, 'platform')->post($url, ['reason' => 'Investigating login failures', 'duration_hours' => 2, 'support_ticket_id' => $betaTicket->id])->assertSessionHasErrors('support_ticket_id');
        $this->actingAs($this->agent, 'platform')->post($url, ['reason' => 'Investigating login failures', 'duration_hours' => 2, 'support_ticket_id' => $ticket->id])->assertSessionHasNoErrors();
        $this->assertSame('requested', SupportAccessGrant::query()->sole()->status);

        $auditor = PlatformUser::factory()->role(PlatformRole::Auditor)->create();
        $this->actingAs($auditor, 'platform')->get($this->platformUrl('/platform/support/'.$ticket->id))->assertOk();
        $this->actingAs($auditor, 'platform')->post($url, ['reason' => 'Auditor wants to look around', 'duration_hours' => 1])->assertForbidden();
    }
}
