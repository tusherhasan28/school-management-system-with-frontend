<?php

namespace App\Modules\Billing\Services;

use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Models\Student;
use App\Support\Tenancy\TenantCache;
use App\Support\Tenancy\TenantStorage;

/**
 * Current usage of the school in the tenant context, per limit key.
 */
class UsageMeter
{
    public function __construct(
        private readonly TenantStorage $storage,
        private readonly TenantCache $cache,
    ) {}

    public function usage(string $key): int
    {
        return match ($key) {
            'students' => Student::query()->where('status', 'active')->count(),
            'staff' => StaffMember::query()->where('status', '!=', 'inactive')->count(),
            'sms_per_month' => (int) NotificationLog::query()->where('channel', 'sms')->whereIn('status', ['queued', 'sent'])
                ->where('created_at', '>=', now()->startOfMonth())->sum('segments'),
            'storage_mb' => $this->storageMb(),
            default => 0,
        };
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return collect(PlanFeatures::limits())->mapWithKeys(fn ($k) => [$k => $this->usage($k)])->all();
    }

    /** Files under the school's storage folder, cached for an hour (walking the folder is not free). */
    public function storageMb(bool $fresh = false): int
    {
        if ($fresh) {
            $this->cache->forget('usage:storage_mb');
        }

        return $this->cache->remember('usage:storage_mb', 3600, function () {
            $disk = $this->storage->disk();
            $bytes = collect($disk->allFiles($this->storage->root()))->sum(fn ($f) => $disk->size($f));

            return (int) ceil($bytes / 1048576);
        });
    }
}
