<?php

namespace App\Modules\Messaging\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * The school's SMS provider settings (tenant). Credentials are encrypted
 * at rest and never sent to the browser.
 *
 * @property string $provider
 * @property string|null $sender_id
 * @property array<string, string>|null $credentials
 * @property bool $is_enabled
 */
class SmsProviderAccount extends Model
{
    use BelongsToSchool;

    protected $fillable = ['provider', 'sender_id', 'credentials', 'is_enabled'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_enabled' => 'boolean'];
    }
}
