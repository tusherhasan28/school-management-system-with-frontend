<?php

namespace App\Support\Ops;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain-text operations alert. Sent synchronously: the queue may be the thing that is down. */
class OpsAlertMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, array{ok: bool, detail: string}>  $failing
     * @param  list<string>  $recovered
     */
    public function __construct(public array $failing, public array $recovered = []) {}

    public function envelope(): Envelope
    {
        $app = config('app.name');
        $subject = $this->failing === []
            ? "[{$app}] recovered: ".implode(', ', $this->recovered)
            : "[{$app}] ALERT: ".implode(', ', array_keys($this->failing));

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-alert');
    }
}
