<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant;

    /**
     * Canonical order statuses. Restaurant kitchen flow lives in KITCHEN_FLOW;
     * the legacy grocery states (Confirmed/Packed/Out for delivery) are kept so
     * existing grocery tenants' orders, filters and notifications keep working.
     */
    public const KITCHEN_FLOW = ['New', 'Accepted', 'Preparing', 'Ready', 'Dispatched', 'Delivered'];
    public const STATUSES = [
        'New', 'Accepted', 'Preparing', 'Ready', 'Dispatched', 'Delivered', 'Customer confirmed', 'Cancelled', 'Rejected',
        // legacy grocery states (still valid, not surfaced on the Kitchen Board)
        'Confirmed', 'Packed', 'In transit', 'Out for delivery',
    ];

    protected $fillable = ['tenant_id','order_no','idempotency_key','customer_name','customer_phone','items_text',
        'items_json','total','amount_paid','location','notes','payment','sell_currency','payment_method','status','channel','rider_id','branch_id',
        'track_token','delivered_at','accepted_at','ready_at','rejected_reason',
        'scheduled_for','sched_stage','sched_reminders','delivery_fee','delivery_zone_id','eta_at',
        'customer_confirmed_at','confirmation_source','rating','rating_comment','rating_at','confirm_reminder_at',
        'status_changed_at','stale_nudged_at','stale_level','delivered_by','workflow_done_at'];
    protected $casts = ['items_json'=>'array','total'=>'decimal:2','amount_paid'=>'decimal:2',
        'delivered_at'=>'datetime','accepted_at'=>'datetime','ready_at'=>'datetime','eta_at'=>'datetime',
        'scheduled_for'=>'datetime','sched_reminders'=>'array',
        'customer_confirmed_at'=>'datetime','rating_at'=>'datetime','confirm_reminder_at'=>'datetime','rating'=>'integer',
        'status_changed_at'=>'datetime','stale_nudged_at'=>'datetime','stale_level'=>'integer','workflow_done_at'=>'datetime'];

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function rider() { return $this->belongsTo(Rider::class); }
    public function payments(): HasMany { return $this->hasMany(LedgerEntry::class)->where('category', 'order_payment'); }

    public function isScheduled(): bool
    {
        return $this->scheduled_for !== null;
    }

    /** The next step in the kitchen flow, or null if the order is finished/off-flow. */
    public function nextKitchenStatus(): ?string
    {
        $i = array_search($this->status, self::KITCHEN_FLOW, true);
        if ($i === false || $i >= count(self::KITCHEN_FLOW) - 1) return null;
        return self::KITCHEN_FLOW[$i + 1];
    }

    public function balanceDue(): float
    {
        return round(max(0, (float) $this->total - (float) $this->amount_paid), 2);
    }

    /** unpaid | partial | paid */
    public function paymentState(): string
    {
        $paid = (float) $this->amount_paid;
        if ($paid <= 0) return 'unpaid';
        return $paid + 0.01 >= (float) $this->total ? 'paid' : 'partial';
    }

    /**
     * The order's sell currency (E4.5, Constitution R7 — money is typed, never overloaded).
     * Prefers the explicit `sell_currency` column; falls back to decoding a legacy
     * "sell:XXX" value from the overloaded `payment` column; defaults to 'USD'.
     */
    public function sellCurrency(): string
    {
        $c = trim((string) ($this->sell_currency ?? ''));
        if ($c !== '') return strtoupper($c);
        $p = (string) ($this->payment ?? '');
        if (strncmp($p, 'sell:', 5) === 0) {
            $dec = strtoupper(trim(substr($p, 5)));
            if ($dec !== '') return $dec;
        }
        return 'USD';
    }

    /**
     * The order's cash/MoMo payment method (E4.5). Prefers the explicit `payment_method`
     * column; falls back to the legacy `payment` value UNLESS that value is actually a
     * "sell:XXX" currency marker (in which case there is no method). Null when unknown.
     */
    public function paymentMethodResolved(): ?string
    {
        $m = trim((string) ($this->payment_method ?? ''));
        if ($m !== '') return $m;
        $p = trim((string) ($this->payment ?? ''));
        if ($p === '' || strncmp($p, 'sell:', 5) === 0) return null;
        return $p;
    }

    /** Operationally complete if EITHER side closed it (seller delivered OR customer confirmed). */
    public function isComplete(): bool
    {
        return $this->delivered_at !== null || $this->customer_confirmed_at !== null;
    }

    /** Whichever happened first — the true moment the order was effectively complete. */
    public function completedAt(): ?\Illuminate\Support\Carbon
    {
        $d = $this->delivered_at;
        $c = $this->customer_confirmed_at;
        if ($d && $c) return $d->lt($c) ? $d : $c;
        return $d ?: $c;
    }

    /** Who closed the loop first: 'seller', 'customer', or null if still open. */
    public function completedBy(): ?string
    {
        $d = $this->delivered_at;
        $c = $this->customer_confirmed_at;
        if (! $d && ! $c) return null;
        if ($d && (! $c || $d->lte($c))) return 'seller';
        return 'customer';
    }
}
