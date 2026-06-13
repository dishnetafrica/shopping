<?php
namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level multi-tenancy. Every model using this trait is automatically
 * filtered to the active tenant and stamped with tenant_id on create.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // Filter every query to the active tenant (unless super-admin context).
        static::addGlobalScope('tenant', function (Builder $q) {
            $ctx = app(TenantContext::class);
            if ($ctx->isSuperAdmin()) return;
            $q->where($q->getModel()->getTable().'.tenant_id', $ctx->id());
        });

        // Stamp tenant_id on insert.
        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
