<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id', 'tenant_id', 'name', 'sku', 'qty', 'unit_price', 'line_total', 'uom', 'pack',
    ];

    protected $casts = [
        'qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'pack' => 'decimal:2',
    ];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
