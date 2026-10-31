<?php

namespace App\Modules\Finance\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * A school's merchant credentials for one installed gateway adapter.
 * Credentials are encrypted at rest and never sent to the browser.
 *
 * @property int $id
 * @property string $gateway
 * @property string $mode
 * @property array<string, string> $credentials
 * @property bool $is_enabled
 */
class PaymentGatewayAccount extends Model
{
    use BelongsToSchool;

    protected $fillable = ['gateway', 'mode', 'credentials', 'is_enabled'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_enabled' => 'boolean'];
    }
}
