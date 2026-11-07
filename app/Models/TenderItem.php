<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** v4.22 TenderBot — one product line the back office must price (see CLOUDBSS-APPLY-v4.22.md). */
class TenderItem extends Model
{
    use BelongsToTenant;   // P0: structural tenant scoping — every access already filters tenant_id.

    protected $fillable = ['tenant_id', 'tender_id', 'line', 'lot', 'item', 'qty', 'qty_num', 'unit',
        'spec', 'brand', 'alt_brand', 'needs_quote', 'status', 'supplier', 'unit_cost', 'currency',
        'margin_pct', 'bid_unit', 'note', 'priced_at', 'priced_by'];

    protected $casts = [
        'needs_quote' => 'bool',
        'qty_num' => 'float',
        'unit_cost' => 'float',
        'margin_pct' => 'float',
        'bid_unit' => 'float',
        'priced_at' => 'datetime',
    ];

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    /** Line cost to us (null until a unit cost is captured). */
    public function lineCost(): ?float
    {
        return ($this->unit_cost !== null && $this->qty_num !== null)
            ? round((float) $this->unit_cost * (float) $this->qty_num, 2) : null;
    }

    /** Line value at our bid price. */
    public function lineBid(): ?float
    {
        return ($this->bid_unit !== null && $this->qty_num !== null)
            ? round((float) $this->bid_unit * (float) $this->qty_num, 2) : null;
    }
}
