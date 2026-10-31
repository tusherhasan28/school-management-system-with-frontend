<?php

namespace App\Modules\Exams\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResultPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $school,
        public string $exam,
        public string $student,
        public string $status,
        public ?string $grade,
        public ?string $gpa,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('exams.result_mail_subject', ['exam' => $this->exam, 'student' => $this->student]))
            ->line(__('exams.result_mail_line', ['student' => $this->student, 'exam' => $this->exam, 'school' => $this->school]))
            ->line(__('exams.result_mail_summary', [
                'status' => __('exams.status_'.$this->status),
                'grade' => $this->grade ?? '—',
                'gpa' => $this->gpa ?? '—',
            ]))
            ->line(__('exams.result_mail_contact'));
    }
}
