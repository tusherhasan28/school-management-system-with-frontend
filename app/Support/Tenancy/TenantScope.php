<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits tenant models to the current school. Fails closed: with no tenant
 * context no rows are returned. Platform code must call withoutTenancy().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $schoolId = app(TenantContext::class)->id();

        if ($schoolId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('school_id'), $schoolId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenancy', fn (Builder $builder) => $builder->withoutGlobalScope($this));
    }
}
