<?php

namespace App\Modules\Setup\Models;

use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A physical campus of a school. Reference implementation of a tenant module:
 * model (BelongsToSchool) → form request → policy → service → thin controller.
 *
 * @property int $id
 * @property int $school_id
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $code
 * @property string|null $address
 * @property string|null $phone
 * @property bool $is_main
 */
class Campus extends Model
{
    /** @use HasFactory<CampusFactory> */
    use BelongsToSchool, HasFactory;

    protected $fillable = ['name', 'name_bn', 'code', 'address', 'phone', 'is_main'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }

    protected static function newFactory(): CampusFactory
    {
        return CampusFactory::new();
    }
}
