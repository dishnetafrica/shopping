<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Support\OrderSla;
use App\Support\Vertical;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Replaces the per-order staleness spam with ONE message per tenant + a link to a page listing every
 * open order. Cadence is vertical-aware:
 *   • restaurant  → near-real-time: alert whenever orders are running late (batched, de-duplicated)
 *   • everyone else (manufacturer / grocery / snacks) → a twice-daily digest at 09:00 and 14:00 of the
 *     open pipeline to review. A manufacturer's days-old "New" order is normal, not an emergency.
 * Scheduled every 30 min (08:00–21:00); each tenant self-gates on the rules above.
 */
class OrderPendingDigestCommand extends Command
{
    protected $signature = 'orders:pending-digest';
    protected $description = 'Send each shop one digest (with a link) of orders needing attention';

    public function handle(): int
    {
        $now  = now();
        $sent = 0;

        Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id')->chunk(50, function ($tenants) use ($now, &$sent) {
            foreach ($tenants as $t) {
                if ($this->processTenant($t, $now)) $sent++;
            }
        });

        $this->info("orders:pending-digest — sent {$sent}.");
        return self::SUCCESS;
    }

    private function processTenant(Tenant $t, \Illuminate\Support\Carbon $now): bool
    {
        $vertical  = Vertical::of($t);
        $overrides = (array) $t->setting('order_sla', []);

        $open = Order::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->whereIn('status', OrderSla::ACTIVE)
            ->orderBy('status_changed_at')
            ->limit(500)->get();

        // Long-haul transit is owned by the shipment chain — don't flag dispatch-state transport orders.
        $open = $open->reject(fn ($o) => OrderSla::isDispatch((string) $o->status) && $this->isTransport($o));
        if ($open->isEmpty()) return false;

        $overdue = $open->filter(function ($o) use ($vertical, $overrides, $now) {
            $since = $o->status_changed_at ?: $o->created_at;
            return $since && $since->lt($now->copy()->subMinutes(OrderSla::staleMinutes($vertical, (string) $o->status, $overrides)));
        });

        if (OrderSla::isRealtime($vertical)) {
            // Restaurant: only when something is actually late, and don't repeat the same set.
            if ($overdue->isEmpty()) return false;
            $sig = md5($overdue->map(fn ($o) => $o->id . ':' . $o->status)->sort()->implode('|'));
            $key = "pendingdigest:{$t->id}";
            if (Cache::get($key) === $sig) return false;
            Cache::put($key, $sig, $now->copy()->addHours(6));
            $n   = $overdue->count();
            $msg = "\u{23F0} {$n} order" . ($n === 1 ? '' : 's') . " running late. Review & update: " . $this->link($t);
        } else {
            // Slow verticals: fire only at the configured digest times (default 09:00 & 14:00).
            $times = (array) ($t->setting('digest_times', ['09:00', '14:00']) ?: ['09:00', '14:00']);
            if (! in_array($now->format('H:i'), $times, true)) return false;
            $n    = $open->count();
            $late = $overdue->count();
            $hour = (int) $now->format('H');
            $hi   = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
            $msg  = "{$hi} \u{1F5C2}\u{FE0F} You have {$n} open order" . ($n === 1 ? '' : 's') . " awaiting action"
                  . ($late > 0 ? " ({$late} overdue)" : '') . ". Review & update: " . $this->link($t);
        }

        NotifyOwner::dispatch($t->id, $msg);
        return true;
    }

    /** Tokened, login-free link to the pending-orders page (token minted once per tenant). */
    private function link(Tenant $t): string
    {
        $token = (string) $t->setting('pending_token', '');
        if ($token === '') {
            $token = Str::lower(Str::random(16));
            $t->putSetting('pending_token', $token);
        }
        return $t->publicUrl('/papi/pending?t=' . $t->id . '&k=' . $token);
    }

    private function isTransport(Order $order): bool
    {
        return Shipment::withoutGlobalScopes()->where('order_id', $order->id)->exists();
    }
}
