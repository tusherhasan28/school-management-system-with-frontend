<?php

namespace App\Modules\Messaging\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An entry in a user's notification centre (database channel only). The
 * text is stored as translation keys + parameters so it shows in the
 * reader's language; parameters must never contain secrets.
 */
class InAppMessage extends Notification
{
    use Queueable;

    /** @param  array<string, string|int>  $params */
    public function __construct(
        public string $titleKey,
        public array $params = [],
        public ?string $url = null,
        public ?string $bodyKey = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->titleKey, 'body' => $this->bodyKey, 'params' => $this->params, 'url' => $this->url];
    }
}
