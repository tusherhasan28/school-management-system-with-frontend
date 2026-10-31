<?php

namespace App\Support\Localization;

/**
 * Picks the Bangla name when the active locale is Bangla and one exists.
 */
final class Localized
{
    public static function name(?string $en, ?string $bn): ?string
    {
        return app()->getLocale() === 'bn' && $bn !== null && $bn !== '' ? $bn : $en;
    }
}
