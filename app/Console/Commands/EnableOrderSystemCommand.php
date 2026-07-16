<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Turns the Order-Trading system ON for a tenant (setting `order_system_enabled`). This is the flag
 * the classic /panel pages and money/purchase endpoints guard on; a trading tenant should have it on.
 * Enabling it is the clean, systemic fix so every screen works uniformly instead of relaxing gates
 * one page at a time.
 *
 *   php artisan tenant:enable-order-system --tenant=ID      # enable for one tenant
 *   php artisan tenant:enable-order-system --tenant=ID --off  # (rare) turn it back off
 *   php artisan tenant:enable-order-system                  # list tenants + their current flag
 *
 * Deliberately per-tenant (no --all): a non-trading tenant should NOT have this on, so we never flip
 * it blindly across the whole platform.
 */
final class EnableOrderSystemCommand extends Command
{
    protected $signature = 'tenant:enable-order-system {--tenant= : tenant id} {--off : turn it OFF instead of on}';
    protected $description = 'Enable (or disable) the order-trading system for a tenant.';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses and their current setting:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $on = (bool) $tt->setting('order_system_enabled', false);
                $this->line('  --tenant=' . $tt->id . '   ' . str_pad($tt->name, 28)
                    . '  order_system_enabled: ' . ($on ? 'ON' : 'off'));
            }
            return self::FAILURE;
        }
        $t = Tenant::find($id);
        if (! $t) { $this->error("Tenant {$id} not found."); return self::FAILURE; }

        $on = ! $this->option('off');
        $was = (bool) $t->setting('order_system_enabled', false);

        $s = $t->settings ?? [];
        $s['order_system_enabled'] = $on;
        $t->settings = $s;
        $t->save();

        $this->info("✓ {$t->name} (tenant {$t->id}): order_system_enabled "
            . ($was ? 'ON' : 'off') . ' → ' . ($on ? 'ON' : 'off'));
        if ($on) {
            $this->line('  All order/trading pages (Payments, Customers 360, Cashbook, Purchasing) now work uniformly.');
        }
        return self::SUCCESS;
    }
}
