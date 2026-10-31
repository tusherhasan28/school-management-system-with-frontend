<?php

namespace App\Modules\Website\Models;

use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per school: theme, home-page content, contact links, SEO defaults.
 *
 * @property int $id
 * @property string $template
 * @property string $theme_color
 * @property string $default_language
 * @property bool $is_published
 * @property bool $show_teachers
 * @property string|null $logo_path
 * @property string|null $hero_image_path
 * @property string|null $principal_photo_path
 */
class WebsiteSetting extends Model
{
    use Bilingual, BelongsToSchool;

    public const TEMPLATES = ['classic', 'modern'];

    public const TEXT_FIELDS = ['tagline', 'hero_title', 'principal_name', 'office_hours', 'hero_text', 'about', 'principal_message', 'seo_description'];

    protected $guarded = ['id', 'school_id', 'created_at', 'updated_at', 'logo_path', 'hero_image_path', 'principal_photo_path'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'show_teachers' => 'boolean'];
    }

    /** The current school's settings, created with defaults on first use. */
    public static function current(): self
    {
        $settings = static::query()->firstOrCreate([], [
            'default_language' => app(\App\Support\Tenancy\TenantContext::class)->require()->locale === 'en' ? 'en' : 'bn',
            'theme_color' => app(\App\Support\Tenancy\TenantContext::class)->require()->primary_color ?: '#0f766e',
        ]);

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }
}
