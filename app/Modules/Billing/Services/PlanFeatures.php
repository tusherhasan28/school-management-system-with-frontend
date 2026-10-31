<?php

namespace App\Modules\Billing\Services;

/**
 * The catalogue of plan features: which keys exist and what type they are.
 * VALUES live in plan_features (database), edited by platform staff; this
 * file only names the switches and limits the code knows how to enforce.
 *
 * Defaults are fail-closed for a plan that has no row for a key (switch off,
 * limit 0). A school with no subscription at all is "unmanaged" and is not
 * limited (see Entitlements).
 */
final class PlanFeatures
{
    /** @var array<string, array{type: 'limit'|'switch', default: int|bool|null, unit?: string}> */
    public const CATALOGUE = [
        'students' => ['type' => 'limit', 'default' => 0, 'unit' => 'students'],
        'staff' => ['type' => 'limit', 'default' => 0, 'unit' => 'staff'],
        'sms_per_month' => ['type' => 'limit', 'default' => 0, 'unit' => 'sms'],
        'storage_mb' => ['type' => 'limit', 'default' => 0, 'unit' => 'MB'],
        'website' => ['type' => 'switch', 'default' => false],
        'parent_portal' => ['type' => 'switch', 'default' => false],
        'student_portal' => ['type' => 'switch', 'default' => false],
        'online_payments' => ['type' => 'switch', 'default' => false],
        'sms' => ['type' => 'switch', 'default' => false],
        'bulk_import' => ['type' => 'switch', 'default' => false],
    ];

    /** @return list<string> */
    public static function limits(): array
    {
        return array_keys(array_filter(self::CATALOGUE, fn ($d) => $d['type'] === 'limit'));
    }

    /** @return list<string> */
    public static function switches(): array
    {
        return array_keys(array_filter(self::CATALOGUE, fn ($d) => $d['type'] === 'switch'));
    }

    /** Normalise a stored value: limits are a non-negative int or null (unlimited), switches a bool. */
    public static function cast(string $key, mixed $value): int|bool|null
    {
        $type = self::CATALOGUE[$key]['type'] ?? null;

        return match ($type) {
            'limit' => $value === null || $value === '' ? null : max(0, (int) $value),
            'switch' => (bool) $value,
            default => throw new \InvalidArgumentException("Unknown plan feature [{$key}]."),
        };
    }
}
