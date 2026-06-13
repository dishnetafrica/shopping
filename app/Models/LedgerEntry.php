<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cashbook entry. Money in (order payments, other income) and money out
 * (expenses, supplier payments, owner draws). Running balance = sum(in) - sum(out).
 */
class LedgerEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'type', 'category', 'order_id', 'amount', 'currency', 'method', 'received_by', 'note',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
