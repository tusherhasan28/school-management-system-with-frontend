<?php

namespace App\Modules\Messaging\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message to one recipient on one channel (tenant). Holds the rendered
 * text so a failed message can be retried exactly; never secrets.
 *
 * @property int $id
 * @property string|null $batch_id
 * @property string $channel
 * @property string $recipient
 * @property string|null $recipient_name
 * @property string|null $template_key
 * @property string $locale
 * @property string|null $subject
 * @property string $body
 * @property int $segments
 * @property string $status
 * @property string|null $provider
 * @property int $attempts
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 */
class NotificationLog extends Model
{
    use BelongsToSchool;

    protected $fillable = ['batch_id', 'user_id', 'channel', 'recipient', 'recipient_name', 'template_key', 'locale', 'subject', 'body', 'segments', 'status', 'provider', 'created_by'];

    protected $attributes = ['status' => 'queued', 'attempts' => 0, 'segments' => 1];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** Phone numbers and emails are partly hidden on screens and in exports. */
    public function maskedRecipient(): string
    {
        if ($this->channel === 'mail') {
            [$name, $domain] = array_pad(explode('@', $this->recipient, 2), 2, '');

            return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
        }

        $digits = $this->recipient;

        return strlen($digits) > 6 ? substr($digits, 0, 5).str_repeat('•', strlen($digits) - 8).substr($digits, -3) : $digits;
    }
}
