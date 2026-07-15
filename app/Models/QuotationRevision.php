<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable entry in a quotation's revision ledger.
 *
 * Immutability is enforced at the ORM layer: any attempt to UPDATE an existing revision throws.
 * The service only ever create()s new revisions, so history can never be overwritten — which is
 * exactly the auditability guarantee the trading workflow needs.
 */
class QuotationRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null; // no updated_at column — rows never change

    protected $fillable = [
        'tenant_id', 'quotation_id', 'rev_no', 'actor_type', 'actor_id', 'actor_name',
        'status_at', 'currency', 'subtotal', 'discount_total', 'tax_total', 'freight',
        'grand_total', 'snapshot', 'change_summary', 'pdf_path',
    ];

    protected $casts = [
        'rev_no'         => 'integer',
        'subtotal'       => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total'      => 'decimal:2',
        'freight'        => 'decimal:2',
        'grand_total'    => 'decimal:2',
        'snapshot'       => 'array',
        'change_summary' => 'array',
        'created_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        // Hard immutability: block any update to an already-persisted revision.
        static::updating(function (self $rev) {
            throw new \RuntimeException('Quotation revisions are immutable and cannot be modified.');
        });
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
