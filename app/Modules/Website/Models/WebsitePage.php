<?php

namespace App\Modules\Website\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom page at /p/{slug}. Bodies are Markdown (no raw HTML).
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $body
 * @property string|null $body_bn
 * @property string|null $seo_description
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class WebsitePage extends Model
{
    use Bilingual, BelongsToSchool;

    protected $fillable = ['slug', 'title', 'title_bn', 'body', 'body_bn', 'seo_description', 'status', 'updated_by'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @param  Builder<WebsitePage>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published');
    }
}
