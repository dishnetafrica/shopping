<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single line on a stored quotation. */
class QuotationItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'quotation_id', 'tenant_id', 'name', 'qty',
        'unit_price', 'line_total', 'unit_label', 'image_url', 'matched',
    ];

    protected $casts = [
        'qty'        => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'matched'    => 'boolean',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
