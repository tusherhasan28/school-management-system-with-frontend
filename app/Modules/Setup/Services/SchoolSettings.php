<?php

namespace App\Modules\Setup\Services;

use App\Models\SchoolSetting;

/**
 * Key/value settings for the current school (tenant scoped by the model).
 */
class SchoolSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        $row = SchoolSetting::query()->where('setting_key', $key)->first();

        return $row?->setting_value ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $row = SchoolSetting::query()->firstOrNew(['setting_key' => $key]);
        $row->setting_value = $value;
        $row->save();
    }
}
