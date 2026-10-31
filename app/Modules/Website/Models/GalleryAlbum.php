<?php

namespace App\Modules\Website\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $taken_on
 * @property bool $is_public
 */
class GalleryAlbum extends Model
{
    use Bilingual, BelongsToSchool;

    protected $fillable = ['title', 'title_bn', 'description', 'taken_on', 'is_public', 'sort_order'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'taken_on' => 'date'];
    }

    /** @return HasMany<GalleryPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(GalleryPhoto::class)->orderBy('sort_order')->orderBy('id');
    }
}
