<?php

namespace App\Modules\Academics\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;

/**
 * Lightweight option lists for forms and filters (one query each, no N+1).
 */
class AcademicOptions
{
    /**
     * @return list<array{id: int, name: string, is_current: bool}>
     */
    public function sessions(): array
    {
        return AcademicSession::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'is_current'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_current' => $s->is_current])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, name_bn: string|null, sections: list<array{id: int, name: string}>}>
     */
    public function classes(bool $activeOnly = true): array
    {
        return SchoolClass::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with(['sections' => fn ($q) => $q->orderBy('name')->select(['id', 'class_id', 'name', 'school_id'])])
            ->orderBy('level_order')
            ->get(['id', 'name', 'name_bn', 'school_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'name_bn' => $c->name_bn,
                'sections' => $c->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            ])
            ->all();
    }

    public function currentSessionId(): ?int
    {
        return AcademicSession::query()->where('is_current', true)->value('id');
    }
}
