<?php

use App\Models\Tenant;
use App\Services\Money\Fx;
use Illuminate\Database\Migrations\Migration;

/**
 * Roadmap E3.2 — give every tenant a single, unified, effective-dated `fx_rates` schedule, seeded
 * from their current accounting rate (Fx::canonicalRates). This freezes today's money-of-record rate
 * explicitly so the FX service (E3.1) has one authoritative source per tenant.
 *
 * Data-only + additive: writes tenant settings, no schema change. Idempotent — skips a tenant that
 * already has fx_rates (never clobbers a hand-set schedule). Nothing reads fx_rates yet (E3.3/E3.4
 * migrate the readers under reconciliation), so this changes no runtime behaviour.
 */
return new class extends Migration {
    public function up(): void
    {
        Tenant::query()->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                try {
                    $s = is_array($t->settings) ? $t->settings : [];
                    if (array_key_exists('fx_rates', $s)) {
                        continue; // already configured — leave it alone
                    }
                    $rates = Fx::canonicalRates($s);
                    $s['fx_rates'] = [array_merge(['from' => null], $rates)];
                    $t->settings = $s;
                    $t->save();
                } catch (\Throwable $e) {
                    \Log::warning('fx_rates backfill skipped tenant ' . $t->id . ': ' . $e->getMessage());
                }
            }
        });
    }

    public function down(): void
    {
        Tenant::query()->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                $s = is_array($t->settings) ? $t->settings : [];
                if (array_key_exists('fx_rates', $s)) {
                    unset($s['fx_rates']);
                    $t->settings = $s;
                    $t->save();
                }
            }
        });
    }
};
