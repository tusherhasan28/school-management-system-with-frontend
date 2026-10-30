<?php

namespace App\Support\Tenancy;

use App\Models\School;
use App\Support\Tenancy\Exceptions\CrossTenantWriteException;
use App\Support\Tenancy\Exceptions\TenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant-owned model.
 *
 * - Adds the fail-closed TenantScope.
 * - Fills school_id from the tenant context on create (never from input).
 * - Refuses to save or delete a row whose school_id differs from the context.
 *
 * @mixin Model
 *
 * @method static Builder<static> withoutTenancy()
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new TenantScope);

        // "saving" fires before "creating", so the fill happens here.
        static::saving(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->check()) {
                throw TenantContextException::missing();
            }

            if (! $model->exists && $model->getAttribute('school_id') === null) {
                $model->setAttribute('school_id', $context->id());
            }

            static::assertSameTenant($model);
        });

        static::deleting(fn (Model $model) => static::assertSameTenant($model));
    }

    public function initializeBelongsToSchool(): void
    {
        // school_id is never mass assignable, whatever the model declares.
        $this->guarded = array_values(array_unique([...$this->guarded, 'school_id']));
        $this->fillable = array_values(array_diff($this->fillable, ['school_id']));
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    protected static function assertSameTenant(Model $model): void
    {
        $contextId = app(TenantContext::class)->id();
        $rowId = $model->getAttribute('school_id');

        if ($contextId === null || (int) $rowId !== $contextId) {
            throw CrossTenantWriteException::for($model::class, $rowId === null ? null : (int) $rowId, $contextId);
        }

        // A row may never be moved to another school.
        if ($model->exists && $model->isDirty('school_id')) {
            throw CrossTenantWriteException::for($model::class, (int) $model->getOriginal('school_id'), $contextId);
        }
    }
}
