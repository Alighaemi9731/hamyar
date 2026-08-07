<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Adds `where tenant_id = ?` to every query on a model using {@see BelongsToTenant}.
 *
 * When there is no tenant the scope adds an impossible condition rather than no
 * condition at all. Omitting the filter would silently fall back to whatever RLS
 * allows, so a future migration that forgot `enableRls()` would turn a missing
 * context into a cross-tenant read. Both layers deny by default.
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
