<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Gap-tolerant, race-safe per-school counters (admission numbers, application
 * numbers, later invoices and receipts). Uses a row lock, so call it inside the
 * transaction that consumes the number.
 */
class TenantSequence
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function next(string $key): int
    {
        $schoolId = $this->tenant->require()->getKey();

        return DB::transaction(function () use ($schoolId, $key) {
            DB::table('tenant_sequences')->insertOrIgnore([
                'school_id' => $schoolId,
                'sequence_key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('tenant_sequences')
                ->where('school_id', $schoolId)
                ->where('sequence_key', $key)
                ->lockForUpdate()
                ->first();

            DB::table('tenant_sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }

    /**
     * Formatted number, e.g. format('admission', '{year}-{seq:5}') → 2026-00042.
     */
    public function format(string $key, string $pattern): string
    {
        $value = $this->next($key);

        return preg_replace_callback('/\{(year|seq)(?::(\d+))?\}/', function (array $m) use ($value) {
            return $m[1] === 'year'
                ? now(app(TenantContext::class)->require()->timezone)->format('Y')
                : str_pad((string) $value, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT);
        }, $pattern);
    }
}
