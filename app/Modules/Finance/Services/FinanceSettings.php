<?php

namespace App\Modules\Finance\Services;

use App\Modules\Setup\Services\SchoolSettings;

/**
 * Finance settings for the current school, with defaults. Late fees are off
 * until a school turns them on and sets its own amount.
 */
class FinanceSettings
{
    public const DEFAULTS = [
        'due_day' => 10,
        'late_fee_enabled' => false,
        'late_fee_kind' => 'fixed', // fixed | percent (of the invoice balance)
        'late_fee_value' => '0.00',
        'late_fee_grace_days' => 7,
        'receipt_note' => null,
    ];

    public function __construct(private readonly SchoolSettings $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, (array) $this->settings->get('finance', []));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values */
    public function save(array $values): void
    {
        $this->settings->put('finance', array_intersect_key($values, self::DEFAULTS) + $this->all());
    }
}
