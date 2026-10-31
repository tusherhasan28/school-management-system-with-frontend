<?php

namespace App\Modules\Support\Services;

use App\Models\ActivityLog;
use App\Models\PlatformUser;
use App\Models\School;
use App\Models\User;
use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\Messaging\Notifications\InAppMessage;
use App\Modules\Support\Models\SupportAccessGrant;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets between a school and the platform, and time-limited,
 * school-approved support access.
 */
class SupportService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** @param  array{subject: string, category: string, priority: string, message: string}  $data */
    public function open(School $school, User $user, array $data): SupportTicket
    {
        return DB::transaction(function () use ($school, $user, $data) {
            $ticket = new SupportTicket([
                'school_id' => $school->id, 'reference' => 'tmp-'.bin2hex(random_bytes(8)), 'created_by_user_id' => $user->id,
                'subject' => $data['subject'], 'category' => $data['category'], 'priority' => $data['priority'],
            ]);
            $ticket->last_activity_at = now();
            $ticket->save();
            $ticket->forceFill(['reference' => sprintf('SUP-%05d', $ticket->id)])->save();
            $this->addMessage($ticket, 'school', $user->id, $user->name, $data['message']);
            $this->audit->tenant('support.ticket_opened', $ticket, new: ['reference' => $ticket->reference, 'subject' => $ticket->subject]);

            return $ticket;
        });
    }

    public function replyFromSchool(SupportTicket $ticket, User $user, string $message): void
    {
        if ($ticket->status === 'closed') {
            throw ValidationException::withMessages(['message' => __('support.ticket_closed')]);
        }

        DB::transaction(function () use ($ticket, $user, $message) {
            $this->addMessage($ticket, 'school', $user->id, $user->name, $message);
            // A school reply reopens a resolved ticket and puts it back in the platform's queue.
            $ticket->forceFill(['status' => in_array($ticket->status, ['waiting', 'resolved'], true) ? 'open' : $ticket->status, 'last_activity_at' => now()])->save();
        });
    }

    public function replyFromPlatform(SupportTicket $ticket, PlatformUser $agent, string $message, bool $internal): void
    {
        DB::transaction(function () use ($ticket, $agent, $message, $internal) {
            $this->addMessage($ticket, 'platform', $agent->id, $agent->name, $message, $internal);

            if (! $internal) {
                $ticket->forceFill(['status' => in_array($ticket->status, ['open', 'in_progress'], true) ? 'waiting' : $ticket->status, 'last_activity_at' => now()])->save();
                $this->notifySchool($ticket->school, 'support.notify_reply', ['reference' => $ticket->reference], '/app/support/'.$ticket->id);
            }

            $this->audit->platform('support.replied', $ticket, $ticket->school_id, ['internal' => $internal]);
        });
    }

    public function setStatus(SupportTicket $ticket, string $status, ?PlatformUser $assignee = null): void
    {
        $ticket->forceFill([
            'status' => $status, 'last_activity_at' => now(), 'closed_at' => $status === 'closed' ? now() : null,
            'assigned_platform_user_id' => $assignee?->id ?? $ticket->assigned_platform_user_id,
        ])->save();
    }

    public function requestAccess(PlatformUser $agent, School $school, string $reason, int $hours, ?SupportTicket $ticket = null): SupportAccessGrant
    {
        $max = (int) config('billing.support_access_max_hours', 24);

        if ($hours < 1 || $hours > $max) {
            throw ValidationException::withMessages(['duration_hours' => __('support.hours_range', ['max' => $max])]);
        }

        if ($ticket && $ticket->school_id !== $school->id) {
            throw ValidationException::withMessages(['support_ticket_id' => __('support.wrong_school')]);
        }

        $grant = SupportAccessGrant::create([
            'school_id' => $school->id, 'platform_user_id' => $agent->id, 'support_ticket_id' => $ticket?->id,
            'reason' => $reason, 'duration_hours' => $hours,
        ]);
        $this->audit->platform('support.access_requested', $grant, $school->id, ['reason' => $reason, 'hours' => $hours]);
        $this->notifySchool($school, 'support.notify_access_requested', ['agent' => $agent->name, 'hours' => $hours], '/app/support');

        return $grant;
    }

    /** The school approves or rejects a pending request. Approval starts the clock. */
    public function decide(SupportAccessGrant $grant, User $user, bool $approve): void
    {
        if ($grant->status !== 'requested') {
            throw ValidationException::withMessages(['grant' => __('support.already_decided')]);
        }

        $grant->forceFill([
            'status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $user->id, 'decided_at' => now(),
            'expires_at' => $approve ? now()->addHours($grant->duration_hours) : null,
        ])->save();
        $this->audit->tenant($approve ? 'support.access_approved' : 'support.access_rejected', $grant, new: ['hours' => $grant->duration_hours, 'reason' => $grant->reason]);
    }

    public function revoke(SupportAccessGrant $grant, User $user): void
    {
        if (! $grant->isActive()) {
            throw ValidationException::withMessages(['grant' => __('support.not_active')]);
        }

        $grant->forceFill(['status' => 'revoked', 'expires_at' => now()])->save();
        $this->audit->tenant('support.access_revoked', $grant);
    }

    /**
     * Read-only diagnostic snapshot of the school for an active grant held by
     * this agent. Every view is written to both audit trails.
     *
     * @return array<string, mixed>
     */
    public function snapshot(SupportAccessGrant $grant, PlatformUser $agent): array
    {
        if ($grant->platform_user_id !== $agent->id || ! $grant->isActive()) {
            abort(403, __('support.no_active_grant'));
        }

        $grant->increment('view_count');
        $this->audit->platform('support.snapshot_viewed', $grant, $grant->school_id, ['grant' => $grant->id]);

        return $this->tenant->run($grant->school, function () use ($grant, $agent) {
            // Recorded in the school's own activity log so the school can see every view.
            ActivityLog::create(['action' => 'support.snapshot_viewed', 'subject_type' => $grant->getMorphClass(), 'subject_id' => $grant->id,
                'new_values' => ['platform_user' => $agent->name, 'reason' => $grant->reason]]);

            return [
                'users' => User::query()->with('roles:id,name')->orderBy('name')->limit(200)->get()->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
                    'roles' => $u->roles->pluck('name')->values(), 'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'two_factor' => $u->two_factor_confirmed_at !== null,
                ]),
                'activity' => ActivityLog::query()->latest('id')->limit(50)->get()->map(fn (ActivityLog $a) => [
                    'id' => $a->id, 'action' => $a->action, 'user_id' => $a->user_id, 'subject' => $a->subject_type ? class_basename($a->subject_type).'#'.$a->subject_id : null,
                    'created_at' => $a->created_at?->toIso8601String(),
                ]),
                'failed_messages' => NotificationLog::query()->where('status', 'failed')->latest('id')->limit(30)->get()->map(fn (NotificationLog $l) => [
                    'id' => $l->id, 'channel' => $l->channel, 'recipient' => $l->maskedRecipient(), 'template' => $l->template_key, 'error' => $l->error_message,
                    'attempts' => $l->attempts, 'created_at' => $l->created_at?->toIso8601String(),
                ]),
                'counts' => app(\App\Modules\Billing\Services\UsageMeter::class)->all(),
            ];
        });
    }

    private function addMessage(SupportTicket $ticket, string $type, int $senderId, string $name, string $message, bool $internal = false): SupportTicketMessage
    {
        return SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => $type, 'sender_id' => $senderId, 'sender_name' => $name,
            'message' => $message, 'is_internal' => $internal,
        ]);
    }

    /** In-app notice to the school's support managers. */
    private function notifySchool(School $school, string $titleKey, array $params, string $url): void
    {
        $this->tenant->run($school, function () use ($titleKey, $params, $url) {
            $users = User::query()->where('status', 'active')->get()->filter(fn (User $u) => $u->can('support.manage'));
            Notification::send($users, new InAppMessage($titleKey, $params, $url));
        });
    }
}
