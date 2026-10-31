<?php

namespace App\Modules\Notices\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * A school event (sports day, prize giving, PTA meeting).
 *
 * @property int $id
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 * @property string|null $starts_at
 * @property string|null $location
 * @property bool $is_public
 */
class Event extends Model
{
    use Bilingual, BelongsToSchool;

    protected $table = 'events';

    protected $fillable = ['title', 'title_bn', 'description', 'description_bn', 'starts_on', 'ends_on', 'starts_at', 'location', 'is_public', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_public' => 'boolean'];
    }
}
