<?php

namespace App\Modules\Attendance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentAbsent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $school, public string $student, public string $date) {}

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
            ->subject(__('attendance.absent_subject', ['student' => $this->student]))
            ->line(__('attendance.absent_line', ['student' => $this->student, 'date' => $this->date, 'school' => $this->school]));
    }
}
