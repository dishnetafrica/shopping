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
        'New', 'Accepted', 'Preparing', 'Ready', 'Dispatched', 'Delivered', 'Cancelled', 'Rejected',
        // legacy grocery states (still valid, not surfaced on the Kitchen Board)
        'Confirmed', 'Packed', 'Out for delivery',
    ];

    protected $fillable = ['tenant_id','order_no','idempotency_key','customer_name','customer_phone','items_text',
        'items_json','total','amount_paid','location','notes','payment','status','channel','rider_id','branch_id',
        'track_token','delivered_at','accepted_at','ready_at','rejected_reason',
        'scheduled_for','sched_stage','sched_reminders','delivery_fee','delivery_zone_id','eta_at'];
    protected $casts = ['items_json'=>'array','total'=>'decimal:2','amount_paid'=>'decimal:2',
        'delivered_at'=>'datetime','accepted_at'=>'datetime','ready_at'=>'datetime','eta_at'=>'datetime',
        'scheduled_for'=>'datetime','sched_reminders'=>'array'];

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
}
