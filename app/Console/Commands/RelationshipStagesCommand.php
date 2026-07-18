<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * relationship:stages — nightly deterministic pass (V4.0).
 * DATA decides the stage floors and ALL numbers; the AI never touches these:
 *   floors: ≥2 orders → REPEAT · 1 order → PURCHASED · silence >45d → DORMANT · VIP = health≥85 & REPEAT
 *   health: RFM 0-100 (recency 40 · frequency 30 · monetary 30), health_prev kept for day-deltas
 *   flags:  VIP · AT_RISK (was a buyer, >60d since order) · WINBACK (dormant past buyer)
 * LOST is never set automatically — that is a human's call, always.
 */
class RelationshipStagesCommand extends Command
{
    protected $signature   = 'relationship:stages {--tenant=0}';
    protected $description = 'V4.0 nightly: deterministic stage floors + RFM health + flags';

    private const PRE_PURCHASE = ['NEW', 'INTERESTED', 'QUOTED', 'NEGOTIATING', 'WAITING_BUDGET', 'DORMANT'];

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        $n = 0;
        foreach ($tenants as $t) {
            if (! (bool) $t->setting('relationship_memory', false)) continue;

            // one pass over order aggregates for the whole tenant (cheap, indexed)
            $agg = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->selectRaw("customer_phone, COUNT(*) c, MAX(created_at) last_at, SUM(CASE WHEN created_at >= NOW() - INTERVAL '365 days' THEN total ELSE 0 END) rev12")
                ->groupBy('customer_phone')->get()->keyBy('customer_phone');
            $median = max(1.0, (float) $agg->pluck('rev12')->filter(fn ($v) => $v > 0)->median());

            foreach (CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->cursor() as $p) {
                $a = $agg->get($p->phone);
                $orders = $a ? (int) $a->c : 0;
                $daysSinceOrder = $a ? now()->diffInDays($a->last_at) : null;
                $silentDays = $p->last_whatsapp_at ? now()->diffInDays($p->last_whatsapp_at) : null;

                // ---- health (math only) ----
                $rec = $daysSinceOrder === null ? 0 : (int) round(40 * max(0, 1 - min($daysSinceOrder, 120) / 120));
                $frq = (int) round(30 * min($orders, 10) / 10);
                $mon = $a ? (int) round(30 * min((float) $a->rev12 / $median, 2) / 2) : 0;
                $health = max(0, min(100, $rec + $frq + $mon));

                // ---- stage floor ----
                $stage = strtoupper((string) ($p->stage ?: 'NEW'));
                if ($orders >= 2) {
                    if (! in_array($stage, ['REPEAT', 'VIP'], true)) $stage = 'REPEAT';
                } elseif ($orders === 1 && in_array($stage, self::PRE_PURCHASE, true)) {
                    $stage = 'PURCHASED';
                } elseif ($orders === 0 && $silentDays !== null && $silentDays > 45
                          && in_array($stage, ['NEW', 'INTERESTED', 'QUOTED', 'NEGOTIATING', 'WAITING_BUDGET'], true)) {
                    $stage = 'DORMANT';
                }
                if ($stage === 'REPEAT' && $health >= 85) $stage = 'VIP';
                if ($stage === 'VIP' && $health < 70) $stage = 'REPEAT';   // VIP is earned continuously

                // ---- flags ----
                $flags = [];
                if ($stage === 'VIP') $flags[] = 'VIP';
                if ($orders >= 1 && $daysSinceOrder !== null && $daysSinceOrder > 60) $flags[] = 'AT_RISK';
                if ($stage === 'DORMANT' && $orders >= 1) $flags[] = 'WINBACK';

                $dirty = false;
                if ($p->health !== null && (int) $p->health !== $health) { $p->health_prev = (int) $p->health; $dirty = true; }
                if ((int) ($p->health ?? -1) !== $health) { $p->health = $health; $dirty = true; }
                if ($p->stage !== $stage) { $p->stage = $stage; $p->stage_changed_at = now(); $dirty = true; }
                if (($p->health_flags ?? []) != $flags) { $p->health_flags = $flags; $dirty = true; }
                if ($dirty) { $p->save(); $n++; }
            }
        }
        $this->info("relationship:stages — {$n} profile(s) updated.");

        return self::SUCCESS;
    }
}
