<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v5.13 - what the shelf is worth, and WHICH CURRENCY every figure in that answer is in.
 *
 * The Juba Stock tile now values stock at cost. A valuation is only as good as its currency, and this
 * platform has three places a "cost" can come from, which do NOT agree:
 *
 *   OrderCostController::purchaseStore  writes products.cost_price as round($toUgx($unit), 2)
 *                                       -> UGX, always, whatever the bill was in
 *   PanelApiController:6440             comments "cost_price is held in base currency (USD)"
 *   ProductListCapability:36            sums stock * cost_price and labels it ctx->baseCurrency()
 *
 * They cannot all be right. So the screen stopped inferring and started reading order_costs, which
 * tags every line with its own `currency` and `rate_to_usd`. This command prints the evidence behind
 * that decision for a real shop, so the total on screen can be checked rather than believed:
 *
 *   - the tenant's base currency, currency_basis and the rate Fx will actually use
 *   - how many products are priced from a real bill vs the catalogue fallback vs not at all
 *   - the purchase currencies actually in use, with line counts
 *   - the total in USD and in UGX, so a wrong one is obvious by its size
 *
 * READ ONLY. Writes nothing, changes no setting, sends nothing.
 *
 *   php artisan stock:value --tenant=17
 */
final class StockValueCommand extends Command
{
    protected $signature = 'stock:value
        {--tenant=17 : tenant id}
        {--top=15 : how many lines to list, biggest first}';

    protected $description = 'Stock at cost with its currency provenance, so the valuation can be checked.';

    public function handle(TenantContext $ctx): int
    {
        $T = (int) $this->option('tenant');

        // The zero-rows trap: Product carries the BelongsToTenant global scope, which filters on
        // TenantContext and fails CLOSED when the console never set one. Without this every count
        // below reads 0 and the command reports an empty shop instead of an unset context.
        $ctx->set($T);

        $t = Tenant::find($T);
        if (! $t) { $this->error('No tenant ' . $T); return self::FAILURE; }

        $line = fn ($s = '') => $this->line((string) $s);
        $rule = fn () => $line(str_repeat('-', 78));

        $line('Stock value provenance - tenant ' . $T . ' - ' . now()->format('Y-m-d H:i'));

        /* ---- 1. what the platform thinks the currencies are ------------------------------- */
        $rule();
        $line('CURRENCY SETTINGS');
        $base  = strtoupper((string) ($t->setting('currency', 'USD') ?: 'USD'));
        $basis = (string) $t->setting('currency_basis', '');
        $mode  = (string) ($t->setting('report_currency_mode', 'base') ?: 'base');
        $fx    = Fx::forTenant($t);
        $rate  = $fx->ratePerUsd('UGX');
        $line('   settings.currency (base)     : ' . $base);
        $line('   settings.currency_basis      : ' . ($basis !== '' ? $basis : '(unset)'));
        $line('   settings.report_currency_mode: ' . $mode);
        // WHERE the rate came from, checked in the order Fx::loadSchedule actually checks it.
        // The first version of this line tested only `rate_ugx_per_usd` and printed "no rate
        // configured" for any tenant using the effective-dated `fx_rates` schedule - which is the
        // newer mechanism and the one this shop uses. It reported a correctly configured rate of
        // 3,710 as a fallback default. A diagnostic that misreports the configuration is worse than
        // no diagnostic, because it sends you looking for a problem that is not there.
        $sched  = $t->setting('fx_rates', null);
        $legacy = (float) $t->setting('rate_ugx_per_usd', 0);
        if (is_array($sched) && $sched) {
            $src = '  (from the fx_rates schedule, ' . count($sched) . ' dated '
                 . (count($sched) === 1 ? 'entry' : 'entries') . ')';
        } elseif ($legacy > 0) {
            $src = '  (from settings.rate_ugx_per_usd)';
        } else {
            $src = '  (FRAMEWORK DEFAULT - no rate configured anywhere)';
        }
        $line('   Fx UGX per 1 USD             : ' . number_format($rate, 2) . $src);
        $line('');
        $line('   CapabilityContext::nativeCurrency() would return: '
            . (strtolower($basis) === 'native' ? $base : ($base === 'USD' ? 'USD' : $base)));
        $line('   -> that is what the OLD valuation tagged UGX costs with. Juba Stock no longer uses it.');

        /* ---- 2. where costs come from ----------------------------------------------------- */
        $rule();
        $line('WHERE EACH COST COMES FROM');

        $prods = Product::where('tenant_id', $T)->get(['id', 'name', 'cost_price']);
        $catCost = []; $names = [];
        foreach ($prods as $p) {
            $names[(int) $p->id]   = (string) $p->name;
            $catCost[(int) $p->id] = $p->cost_price === null ? null : (float) $p->cost_price;
        }

        $buy = [];
        $byCurrency = [];
        try {
            $rows = DB::table('order_costs')
                ->where('tenant_id', $T)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereNotNull('product_id')->where('qty', '>', 0)->where('unit_cost', '>', 0)
                ->orderBy('product_id')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['product_id', 'unit_cost', 'currency', 'rate_to_usd', 'created_at']);
            foreach ($rows as $r) {
                $cur = strtoupper(trim((string) ($r->currency ?? ''))) ?: 'UGX';
                $byCurrency[$cur] = ($byCurrency[$cur] ?? 0) + 1;
                $pid = (int) $r->product_id;
                if (isset($buy[$pid])) { continue; }
                $rt = (float) ($r->rate_to_usd ?? 0);
                $buy[$pid] = ['unit' => (float) $r->unit_cost, 'currency' => $cur,
                              'usd' => $rt > 0 ? (float) $r->unit_cost / $rt : null,
                              'date' => substr((string) $r->created_at, 0, 10)];
            }
        } catch (\Throwable $e) {
            $this->error('   order_costs could not be read: ' . $e->getMessage());
            $this->error('   -> every cost below is the catalogue fallback. The screen says so too.');
        }

        $line('   purchase lines with a product and a unit cost, by currency:');
        if (! $byCurrency) {
            $line('      NONE. Every product will fall back to products.cost_price.');
        } else {
            arsort($byCurrency);
            foreach ($byCurrency as $c => $n) { $line('      ' . str_pad($c, 6) . number_format($n) . ' lines'); }
        }
        $line('   products priced from their last bill : ' . number_format(count($buy)));

        /* ---- 3. the openings, and what they are worth ------------------------------------- */
        $rule();
        $line('THE SHELF');

        $open = [];
        try {
            foreach (DB::table('stock_openings')->where('tenant_id', $T)
                        ->orderBy('counted_at')->orderBy('id')
                        ->get(['product_id', 'qty', 'counted_at']) as $o) {
                $open[(int) $o->product_id] = ['qty' => (float) $o->qty, 'at' => (string) $o->counted_at];
            }
        } catch (\Throwable $e) {
            $this->error('   stock_openings could not be read: ' . $e->getMessage());
            return self::FAILURE;
        }
        if (! $open) {
            $line('   No counts recorded yet. Record one with: php artisan stock:count --tenant=' . $T);
            return self::SUCCESS;
        }

        // Current = counted + bought since - sold since. This command must arrive at the SAME number
        // the screen shows, so it asks the capability instead of re-deriving the rule: two
        // implementations of one rule is how they quietly drift apart, and then nobody knows which is
        // right. CapabilityContext is a plain constructor, so it can be built here from any user of
        // the tenant - the capability itself checks no permission, the controller does.
        $screen = null;
        $u = \App\Models\User::withoutGlobalScopes()->where('tenant_id', $T)->orderBy('id')->first();
        if (! $u) {
            $line('   (no user on this tenant, so the live screen cannot be projected here)');
        } else {
            try {
                $c2 = new \App\Capability\Contracts\CapabilityContext(
                    tenant: $t, user: $u, role: (string) ($u->role ?? 'owner'), permissions: ['menu:products'],
                );
                $screen = (new \App\Capability\Process\JubaStockCapability)->project($c2)->toArray();
            } catch (\Throwable $e) {
                $line('   (could not project the live screen: ' . get_class($e) . ' - ' . $e->getMessage() . ')');
            }
        }

        $totUsd = 0.0; $totUgx = 0.0; $fromBill = 0; $fromCat = 0; $noCost = 0; $onHand = 0;
        $listed = [];
        foreach ($open as $pid => $o) {
            $qty = (float) $o['qty'];      // fallback quantity; overwritten below when available
            if ($qty <= 0) { continue; }
            $onHand++;

            $c = $buy[$pid] ?? null;
            if ($c === null) {
                $cp = $catCost[$pid] ?? null;
                if ($cp !== null && $cp > 0) {
                    $c = ['unit' => $cp, 'currency' => 'UGX', 'usd' => null, 'date' => null];
                    $fromCat++;
                } else {
                    $noCost++;
                    continue;
                }
            } else {
                $fromBill++;
            }

            $usdUnit = $c['usd'] ?? ($c['currency'] === 'USD' ? $c['unit'] : $c['unit'] / max($rate, 0.000001));
            $totUsd += $usdUnit * $qty;
            $totUgx += $usdUnit * $qty * $rate;
            $listed[] = ['n' => $names[$pid] ?? ('#' . $pid), 'q' => $qty, 'u' => $c['unit'],
                         'c' => $c['currency'], 'v' => $usdUnit * $qty];
        }

        $line('   products with a COUNT above zero     : ' . number_format($onHand));
        $line('      priced from a bill                : ' . number_format($fromBill));
        $line('      priced from products.cost_price   : ' . number_format($fromCat) . '  (read as UGX)');
        $line('      no cost anywhere, EXCLUDED        : ' . number_format($noCost));
        $line('');
        $line('   at COUNTED quantities, in USD : $' . number_format($totUsd, 2));
        $line('   at COUNTED quantities, in UGX : ' . number_format($totUgx, 0) . ' UGX');
        $line('   -> at ' . number_format($rate, 0) . ' UGX/USD. These two must differ by exactly that rate.');
        $line('      A shelf worth $2.5 MILLION means a UGX figure is wearing a dollar sign.');

        /* ---- 3b. what the screen itself is showing ---------------------------------------- */
        if ($screen !== null) {
            $rule();
            $line('WHAT THE SCREEN SHOWS (the same capability the browser calls)');
            $v = $screen['meta']['value'] ?? [];
            foreach (($screen['financials'] ?? []) as $tile) {
                if (($tile['key'] ?? '') === 'value') {
                    $line('   Stock value tile      : ' . (string) ($tile['value'] ?? '?'));
                }
            }
            $line('   display currency      : ' . (string) ($v['display'] ?? '?'));
            $line('   reporting mode        : ' . (string) ($v['mode'] ?? '?'));
            $line('   priced from a bill    : ' . (string) ($v['fromPurchase'] ?? '?'));
            $line('   priced from catalogue : ' . (string) ($v['fromCatalogue'] ?? '?') . '  (read as ' . (string) ($v['costBase'] ?? '?') . ')');
            $line('   excluded, no cost     : ' . (string) ($v['noCost'] ?? '?'));
            $line('   rows on screen        : ' . count($screen['table']['rows'] ?? []));
            $line('');
            $line('   Two reasons the screen is HIGHER than the block above, both correct:');
            $line('     - the tile includes purchases and sales since each count; that block does not.');
            $adopted = (int) ($v['fromPurchase'] ?? 0) + (int) ($v['fromCatalogue'] ?? 0) - ($fromBill + $fromCat);
            if ($adopted > 0) {
                $line('     - ' . $adopted . ' product' . ($adopted === 1 ? '' : 's')
                    . ' had nothing before the count and were adopted automatically from their');
                $line('       first purchase, so they have no counted quantity for that block to use.');
            }
            if (! empty($v['error'])) {
                $line('   *** the screen could not read purchase prices - every cost there is the catalogue figure.');
            }
        }

        /* ---- 4. the biggest lines --------------------------------------------------------- */
        $top = max(1, (int) $this->option('top'));
        usort($listed, fn ($a, $b) => $b['v'] <=> $a['v']);
        $rule();
        $line('BIGGEST ' . min($top, count($listed)) . ' LINES (at counted quantity)');
        foreach (array_slice($listed, 0, $top) as $r) {
            $line('   ' . str_pad('$' . number_format($r['v'], 2), 14)
                . str_pad(rtrim(rtrim(number_format($r['q'], 3, '.', ''), '0'), '.') . ' x '
                    . number_format($r['u'], 2) . ' ' . $r['c'], 30)
                . mb_substr($r['n'], 0, 44));
        }

        if ($noCost > 0) {
            $rule();
            $line('NOTE: ' . $noCost . ' counted item' . ($noCost === 1 ? '' : 's')
                . ' have no cost price and no purchase on file, so the real shelf is worth MORE');
            $line('than the figures above. Buy them once through Purchases and the cost fills in by itself.');
        }

        return self::SUCCESS;
    }
}
