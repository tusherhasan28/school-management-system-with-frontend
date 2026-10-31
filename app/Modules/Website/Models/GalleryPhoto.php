<?php

namespace App\Modules\Website\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $gallery_album_id
 * @property string $path
 * @property string $thumb_path
 * @property int|null $width
 * @property int|null $height
 * @property string|null $caption
 * @property string|null $caption_bn
 */
class GalleryPhoto extends Model
{
    use Bilingual, BelongsToSchool;

    protected $fillable = ['gallery_album_id', 'path', 'thumb_path', 'width', 'height', 'caption', 'caption_bn', 'sort_order'];

    /** @return BelongsTo<GalleryAlbum, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'gallery_album_id');
    }
}
