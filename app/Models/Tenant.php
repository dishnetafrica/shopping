<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name','slug','status','plan','trial_ends_at','paid_until','billing_note',
        'whatsapp_driver','whatsapp_instance','whatsapp_number','order_prefix','settings',
    ];

    protected $casts = [
        'settings'      => 'array',
        'trial_ends_at' => 'datetime',
        'paid_until'    => 'datetime',
    ];

    /** Every new business starts on a 30-day full-feature trial. */
    protected static function booted(): void
    {
        static::creating(function (Tenant $t) {
            if (empty($t->plan)) $t->plan = 'free';
            if (empty($t->trial_ends_at)) $t->trial_ends_at = now()->addDays(30);
        });
    }

    public function users(): HasMany    { return $this->hasMany(User::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function orders(): HasMany   { return $this->hasMany(Order::class); }
    public function riders(): HasMany   { return $this->hasMany(Rider::class); }

    public function setting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    // ---------------- Plans & billing ----------------

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function trialDaysLeft(): int
    {
        return $this->onTrial() ? (int) ceil(now()->floatDiffInDays($this->trial_ends_at)) : 0;
    }

    /** The plan whose features actually apply right now. */
    public function effectivePlan(): string
    {
        if ($this->onTrial()) return 'pro';                 // trial = full features
        if (in_array($this->plan, ['starter', 'pro'], true)) {
            // paid plans stay active unless a paid_until date has lapsed
            if ($this->paid_until === null || $this->paid_until->isFuture()) {
                return $this->plan;
            }
            return 'free';                                  // lapsed -> downgrade
        }
        return 'free';
    }

    public function planConfig(?string $plan = null): array
    {
        $plan = $plan ?: $this->effectivePlan();
        return config('plans.' . $plan, config('plans.free'));
    }

    public function can(string $feature): bool
    {
        return in_array($feature, $this->planConfig()['features'] ?? [], true);
    }

    public function orderCap(): ?int
    {
        return $this->planConfig()['order_cap'] ?? null;
    }

    public function ordersThisMonth(): int
    {
        return Order::withoutGlobalScopes()
            ->where('tenant_id', $this->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function overOrderCap(): bool
    {
        $cap = $this->orderCap();
        return $cap !== null && $this->ordersThisMonth() >= $cap;
    }

    /** Short human label for the panel/admin. */
    public function planLabel(): string
    {
        if ($this->onTrial()) return 'Free trial · ' . $this->trialDaysLeft() . ' days left';
        $eff = $this->effectivePlan();
        $nm  = $this->planConfig($eff)['name'] ?? ucfirst($eff);
        if (in_array($this->plan, ['starter', 'pro'], true) && $eff === 'free') {
            return ucfirst($this->plan) . ' (expired)';
        }
        return $nm;
    }
}
