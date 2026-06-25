<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name','slug','custom_domain','status','plan','trial_ends_at','paid_until','billing_note',
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

    /** Write a single setting (dot-notation supported) and persist. */
    public function putSetting(string $key, $value): void
    {
        $s = $this->settings ?? [];
        if (! is_array($s)) $s = [];
        data_set($s, $key, $value);
        $this->settings = $s;
        $this->save();
    }

    /**
     * Build a public URL for this shop, rooted on its own custom domain when one is
     * set, otherwise the platform URL (APP_URL). Use this for links sent in
     * server-side messages — WhatsApp order acks, bot replies — where there is no
     * browser request host to derive from, so the customer sees the shop's own
     * domain instead of the platform domain. $path should be root-relative ('/...').
     * The /papi/track (and other public) routes are host-agnostic, so they resolve
     * correctly on the custom domain too.
     */
    public function publicUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $dom  = strtolower(trim((string) ($this->custom_domain ?? '')));
        $dom  = preg_replace('#^https?://#', '', $dom);   // tolerate scheme if ever stored
        $dom  = preg_replace('#/.*$#', '', $dom);          // tolerate trailing path
        return $dom !== '' ? 'https://' . $dom . $path : url($path);
    }

    /** Staff-login seat limit for the active plan. null = unlimited. */
    public function userCap(): ?int
    {
        return config('plans.' . $this->effectivePlan() . '.user_cap', 1);
    }

    public function staffCount(): int
    {
        return User::where('tenant_id', $this->id)->count();
    }

    public function atUserLimit(): bool
    {
        $cap = $this->userCap();
        return $cap !== null && $this->staffCount() >= $cap;
    }

    /** A "marketing" tenant is CloudBSS's own sales line — uses the sales bot, not the shop bot. */
    public function isMarketing(): bool
    {
        return $this->setting('bot_kind') === 'marketing';
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

    /** Apply a successful payment: set plan and extend the paid period. */
    public function applyPaidPlan(string $plan, int $months = 1): void
    {
        $base = ($this->paid_until && $this->paid_until->isFuture()) ? $this->paid_until : now();
        $this->plan = $plan;
        $this->paid_until = $base->copy()->addMonths(max(1, $months));
        $this->trial_ends_at = null;     // trial is over once they pay
        $this->save();
    }

    /** Phone number(s) that receive new-order alerts & payment receipts. */
    public function ownerAlertNumbers(): array
    {
        $raw  = (string) $this->setting('owner_alert_phone', '');
        $nums = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(array_map(fn ($n) => preg_replace('/[^0-9]/', '', $n), $nums)));
    }

    /**
     * Lead/ticket notification recipients with roles. Stored as settings.lead_recipients
     * = [ ['phone'=>'2567..','role'=>'sales','name'=>'Asha'], ... ]. Falls back to the
     * owner alert numbers (as 'sales') so leads are never dropped before it's configured.
     *
     * @return array<int,array{phone:string,role:string,name:string}>
     */
    public function leadRecipients(?string $role = null): array
    {
        $raw = $this->setting('lead_recipients', []);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $r) {
                if (array_key_exists('enabled', (array) $r) && ! (bool) $r['enabled']) continue; // skip disabled
                $phone = preg_replace('/[^0-9]/', '', (string) ($r['phone'] ?? ''));
                if ($phone === '') continue;   // skip missing phone → never assign a lead into a black hole
                $out[] = [
                    'phone' => $phone,
                    'role'  => strtolower((string) ($r['role'] ?? 'sales')) ?: 'sales',
                    'name'  => (string) ($r['name'] ?? ''),
                ];
            }
        }
        if (! $out) {
            foreach ($this->ownerAlertNumbers() as $n) {
                $out[] = ['phone' => $n, 'role' => 'sales', 'name' => ''];
            }
        }
        if ($role !== null) {
            $role = strtolower($role);
            $filtered = array_values(array_filter($out, fn ($r) => $r['role'] === $role));
            // If nobody holds that role, fall back to everyone so nothing is dropped.
            return $filtered ?: $out;
        }
        return $out;
    }

    /** Round-robin pick from a recipient list; advances and persists the pointer. */
    public function nextRoundRobin(array $recipients): ?array
    {
        $recipients = array_values($recipients);
        $n = count($recipients);
        if ($n === 0) return null;
        $i = (int) $this->setting('lead_rr_index', 0);
        $pick = $recipients[$i % $n];
        $this->putSetting('lead_rr_index', ($i + 1) % max(1, $n));
        return $pick;
    }
}
