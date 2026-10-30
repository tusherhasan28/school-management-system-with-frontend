<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued: the job payload carries the school id (QueueTenancy).
 */
class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $schoolName, public string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('invitation.subject', ['school' => $this->schoolName]))
            ->greeting(__('invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('invitation.line', ['school' => $this->schoolName]))
            ->action(__('invitation.action'), $this->url)
            ->line(__('invitation.expiry', ['days' => \App\Services\Tenancy\InvitationService::TTL_DAYS]));
    }
}
