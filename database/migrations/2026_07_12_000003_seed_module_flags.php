<?php

use App\Models\Tenant;
use App\Support\ModuleDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Roadmap E1.4 — backfill each tenant's settings.modules map so no tenant loses a feature
 * it currently uses when the restaurant module became default-OFF in E1.3.
 *
 * Data-only + additive: writes tenant settings, no schema change. Idempotent — only sets a
 * module key that is not already present, so re-running (or a later admin edit) is preserved.
 */
return new class extends Migration {
    public function up(): void
    {
        Tenant::query()->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                try {
                    $s = is_array($t->settings) ? $t->settings : [];
                    $modules = is_array($s['modules'] ?? null) ? $s['modules'] : [];

                    $defaults = ModuleDefaults::forTenant($t);
                    $changed  = false;
                    foreach ($defaults as $key => $val) {
                        if (! array_key_exists($key, $modules)) {   // never clobber an explicit choice
                            $modules[$key] = $val;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $s['modules'] = $modules;
                        $t->settings = $s;
                        $t->save();
                    }
                } catch (\Throwable $e) {
                    // Never let one tenant abort the backfill; it can be re-run safely.
                    \Log::warning('module flag backfill skipped tenant ' . $t->id . ': ' . $e->getMessage());
                }
            }
        });
    }

    public function down(): void
    {
        // Reversible: drop the backfilled map. Standard modules default-on and restaurant
        // default-off (E1.3), so removing the map restores pre-migration behaviour.
        Tenant::query()->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                $s = is_array($t->settings) ? $t->settings : [];
                if (array_key_exists('modules', $s)) {
                    unset($s['modules']);
                    $t->settings = $s;
                    $t->save();
                }
            }
        });
    }
};
