<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stored, numbered credit note with its frozen line items. */
class CreditNote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'credit_note_no', 'invoice_id', 'invoice_no', 'order_id', 'return_id',
        'customer_name', 'customer_phone', 'bill_address', 'currency', 'subtotal', 'total',
        'reason_code', 'title', 'status', 'source', 'notes', 'issued_at', 'pdf_path', 'meta',
        'applied', 'refund_method',   // v4.88 (ADR-019) — allocated to its invoice, or held on account
    ];

    protected $casts = [
        'subtotal' => 'decimal:2', 'total' => 'decimal:2',
        'issued_at' => 'datetime', 'meta' => 'array',
        'applied' => 'boolean',
    ];

    /** v4.88 — a live credit that has been netted off its own invoice's balance. */
    public function isAppliedToInvoice(): bool
    {
        return (bool) $this->applied && ! $this->isVoid() && (int) $this->invoice_id > 0;
    }

    public function items(): HasMany { return $this->hasMany(CreditNoteItem::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function isVoid(): bool { return $this->status === 'void'; }

    /** Human label for a reason code. */
    public static function reasonLabel(string $code): string
    {
        return [
            'return'           => 'Return of goods',
            'price_adjustment' => 'Price adjustment',
            'overpayment'      => 'Over-payment refund',
            'goodwill'         => 'Goodwill / discount',
            'damage'           => 'Damaged / short supply',
            'other'            => 'Adjustment',
        ][$code] ?? 'Adjustment';
    }
}
