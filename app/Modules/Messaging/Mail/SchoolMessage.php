<?php

namespace App\Modules\Messaging\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain-text email from a school, already rendered from its template. */
class SchoolMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $text, public string $schoolName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.school-message');
    }
}
