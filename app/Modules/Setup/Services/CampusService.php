<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Models\Campus;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class CampusService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Campus
    {
        $this->tenant->require();

        return DB::transaction(function () use ($data) {
            $campus = Campus::create($data);
            $this->ensureSingleMain($campus);
            $this->audit->tenant('campus.created', $campus, new: $campus->only($campus->getFillable()));

            return $campus;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Campus $campus, array $data): Campus
    {
        return DB::transaction(function () use ($campus, $data) {
            $old = $campus->only(array_keys($data));
            $campus->update($data);
            $this->ensureSingleMain($campus);
            $this->audit->tenant('campus.updated', $campus, $old, $campus->only(array_keys($data)));

            return $campus;
        });
    }

    public function delete(Campus $campus): void
    {
        DB::transaction(function () use ($campus) {
            $this->audit->tenant('campus.deleted', $campus, $campus->only($campus->getFillable()));
            $campus->delete();
        });
    }

    private function ensureSingleMain(Campus $campus): void
    {
        if ($campus->is_main) {
            Campus::query()->whereKeyNot($campus->getKey())->where('is_main', true)->update(['is_main' => false]);
        }
    }
}
