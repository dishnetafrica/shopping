<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stored quotation with its line items and lifecycle status. */
class Quotation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'quote_no', 'customer_name', 'customer_phone', 'currency',
        'total', 'status', 'source', 'valid_until', 'pdf_path', 'order_id',
        'send_count', 'last_sent_at', 'meta',
    ];

    protected $casts = [
        'total'        => 'decimal:2',
        'valid_until'  => 'date',
        'last_sent_at' => 'datetime',
        'meta'         => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted' && $this->order_id;
    }
}
