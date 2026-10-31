<?php

namespace App\Modules\Website\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $location
 * @property string $label
 * @property string|null $label_bn
 * @property string $link_type
 * @property string|null $builtin
 * @property int|null $website_page_id
 * @property string|null $url
 * @property int $sort_order
 * @property bool $is_visible
 */
class WebsiteMenuItem extends Model
{
    use Bilingual, BelongsToSchool;

    /** Built-in website sections a menu item can point to. */
    public const BUILTINS = ['home', 'about', 'academics', 'teachers', 'notices', 'events', 'gallery', 'admission', 'contact'];

    protected $fillable = ['location', 'label', 'label_bn', 'link_type', 'builtin', 'website_page_id', 'url', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    /** @return BelongsTo<WebsitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'website_page_id');
    }
}
