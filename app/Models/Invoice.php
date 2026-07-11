<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stored, numbered invoice with its frozen line items. */
class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'invoice_no', 'order_id', 'customer_name', 'customer_phone', 'bill_address',
        'currency', 'subtotal', 'discount', 'freight', 'total', 'amount_paid', 'balance',
        'title', 'status', 'source', 'notes', 'issued_at', 'due_at', 'pdf_path', 'meta',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'freight' => 'decimal:2',
        'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance' => 'decimal:2',
        'issued_at' => 'datetime', 'due_at' => 'date', 'meta' => 'array',
    ];

    public function items(): HasMany { return $this->hasMany(InvoiceItem::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function isVoid(): bool { return $this->status === 'void'; }
}
