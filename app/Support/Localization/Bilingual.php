<?php

namespace App\Support\Localization;

/**
 * For models with `field` + `field_bn` columns: text in the active language,
 * falling back to the other language when one is empty.
 */
trait Bilingual
{
    public function local(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $en = $this->getAttribute($field);
        $bn = $this->getAttribute($field.'_bn');

        $first = $locale === 'bn' ? $bn : $en;
        $second = $locale === 'bn' ? $en : $bn;

        return $first !== null && $first !== '' ? $first : (($second !== null && $second !== '') ? $second : null);
    }
}
