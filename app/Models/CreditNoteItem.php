<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteItem extends Model
{
    protected $fillable = [
        'credit_note_id', 'tenant_id', 'name', 'sku', 'qty', 'unit_price', 'line_total', 'uom', 'pack',
    ];

    protected $casts = [
        'qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'pack' => 'decimal:2',
    ];

    public function creditNote(): BelongsTo { return $this->belongsTo(CreditNote::class); }
}
