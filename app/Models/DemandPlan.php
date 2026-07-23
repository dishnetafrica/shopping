<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Demand Plan → Procurement Request. The header; lines carry the per-product planning + procurement
 * figures. Planning document only — money posts through order_costs, stock through StockLedger.
 */
class DemandPlan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'plan_no', 'title', 'plan_date', 'transport_mode', 'cover_days',
        'shipment_ref', 'shipment_id', 'status', 'created_by', 'notes', 'submitted_at',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'cover_days' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(DemandPlanLine::class);
    }

    /** Procurement completion 0–100 across all lines (by requested vs purchased quantity). */
    public function procurementPct(): int
    {
        $req = (float) $this->lines->sum('requested_qty');
        if ($req <= 0) {
            return 0;
        }
        $got = (float) $this->lines->sum(fn ($l) => min((float) $l->requested_qty, (float) $l->qty_purchased));

        return (int) round(min(100, max(0, $got / $req * 100)));
    }
}
