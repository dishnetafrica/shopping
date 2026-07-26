<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A field-work job (Asset Lifecycle · Phase 2 · S5) — installation, service visit, repair or inspection.
 * The `status` column is the source of truth; lifecycle events on the spine are the audit log.
 * Tenant-scoped.
 */
class WorkOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'number', 'type', 'status',
        'customer_id', 'customer_name', 'customer_phone', 'site_address', 'location',
        'order_id', 'invoice_id', 'quotation_id',
        'assigned_to', 'assigned_user_id',
        'scheduled_for', 'started_at', 'completed_at', 'completed_by',
        'notes', 'meta',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'meta'          => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }
}
