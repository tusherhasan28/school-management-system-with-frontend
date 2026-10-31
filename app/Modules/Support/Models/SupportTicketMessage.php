<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message in a ticket. Internal notes are platform-only.
 *
 * @property string $sender_type
 * @property int $sender_id
 * @property string $sender_name
 * @property string $message
 * @property bool $is_internal
 */
class SupportTicketMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['support_ticket_id', 'sender_type', 'sender_id', 'sender_name', 'message', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
    }
}
