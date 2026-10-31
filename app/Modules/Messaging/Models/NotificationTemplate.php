<?php

namespace App\Modules\Messaging\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * A school's own wording for one message type on one channel (tenant).
 * Without a row, the built-in default text is used (MessageTemplates).
 *
 * @property string $template_key
 * @property string $channel
 * @property string|null $subject
 * @property string|null $subject_bn
 * @property string $body
 * @property string|null $body_bn
 * @property bool $is_active
 */
class NotificationTemplate extends Model
{
    use Bilingual, BelongsToSchool;

    protected $fillable = ['template_key', 'channel', 'subject', 'subject_bn', 'body', 'body_bn', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
