<?php

namespace App\Modules\Website\Services;

use Illuminate\Support\Str;

/**
 * Markdown for website pages, notices and events. Raw HTML is escaped and
 * unsafe links (javascript:, data:) are dropped, so editors cannot inject
 * scripts into the public site.
 */
final class Markdown
{
    public static function render(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        return Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    /** Plain-text excerpt for listings and meta descriptions. */
    public static function excerpt(?string $text, int $length = 160): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(self::render($text))) ?? '');

        return Str::limit(html_entity_decode($plain, ENT_QUOTES), $length);
    }
}
