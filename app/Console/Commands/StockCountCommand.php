<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\PurchaseNameMatch;
use App\Services\Inventory\StockLedger;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v5.07 — record a physical count from the shop floor.
 *
 * ── why this exists ─────────────────────────────────────────────────────────────────────────────
 *
 * Juba counted five lines on 14 Aug and there was nowhere to put the numbers. `trust:links --count`
 * mints a blind-count page, but the engine picks WHICH items to count — it cannot accept a count
 * somebody has already done on their own list. This takes that list.
 *
 * ── what it will not do ─────────────────────────────────────────────────────────────────────────
 *
 * It does not touch a purchase, an invoice or an order. A count is the strongest evidence a shop
 * can have — somebody stood in the store and looked — but it is a DIFFERENT FACT from "purchased
 * minus sold", and the Juba Stock screen goes on reporting the documents afterwards.
 *
 * When the two disagree, that disagreement is the finding. Cling film came back 115 against 112
 * derived, which is a 370-carton line agreeing within three. Foil came back 53 against 108, and the
 * 55 missing cartons are the same 55 hiding inside the invoice wordings that do not match a product
 * name. Editing a document to make those meet would delete the only signal pointing at the problem.
 *
 * So the count is written where counts belong: a `trust_count` movement on the ledger, plus the
 * products.stock cache with its confidence and date stamped, exactly as TrustCountController does
 * it for a blind count.
 *
 * ── use ─────────────────────────────────────────────────────────────────────────────────────────
 *
 *   php artisan stock:count --tenant=17 "cling film=115" "aluminium foil=53" "food container=34"
 *   php artisan stock:count --tenant=17 "cling film=115" --by="Ashish Kareliya" --write
 *
 * A search term matching two products is REFUSED, never guessed; the dry run prints the candidates
 * with the exact `#id=qty` form to re-run with. Dry run is the default and the same count is never
 * recorded twice on one day.
 */
final class StockCountCommand extends Command
{
    protected $signature = 'stock:count
        {item* : counted lines, each "search text=qty" or "#productId=qty"}
        {--tenant= : tenant id (required)}
        {--by=Stock count : who counted}
        {--date= : the count date, defaults to today}
        {--location= : stock location; defaults to the shop home. Juba goods need juba-consignment}
        {--write : actually record it (default is a dry run)}';

    protected $description = 'Record a physical stock count against products, without touching any document.';

    public function handle(TenantContext $ctx): int
    {
        $tid = (int) $this->option('tenant');
        if (! $tid) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $t) {
                $this->line('  --tenant=' . $t->id . '   ' . $t->name);
            }

            return self::FAILURE;
        }
        $tenant = Tenant::find($tid);
        if (! $tenant) { $this->error('No tenant ' . $tid); return self::FAILURE; }
        $ctx->set($tid);

        $write = (bool) $this->option('write');
        $by    = trim((string) $this->option('by')) ?: 'Stock count';
        $date  = trim((string) ($this->option('date') ?: now()->format('Y-m-d')));

        $lines = [];
        foreach ((array) $this->argument('item') as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') continue;
            $at = strrpos($raw, '=');
            if ($at === false) { $this->error('Bad item "' . $raw . '" — expected "name=qty".'); return self::FAILURE; }
            $needle = trim(substr($raw, 0, $at));
            $qty    = (float) str_replace(',', '', trim(substr($raw, $at + 1)));
            if ($needle === '') { $this->error('Bad item "' . $raw . '" — no search text.'); return self::FAILURE; }
            if ($qty < 0)       { $this->error('Bad item "' . $raw . '" — negative quantity.'); return self::FAILURE; }
            $lines[] = [$needle, $qty];
        }
        if (! $lines) { $this->error('Nothing to count.'); return self::FAILURE; }

        $this->line('');
        $this->info('PHYSICAL COUNT — ' . $tenant->name . ' — by ' . $by . ' on ' . $date);
        $this->line($write ? '*** WRITE MODE ***' : 'DRY RUN — nothing will be written (add --write when you are satisfied)');

        [$bought, $sold] = $this->documents($tid);

        $home     = StockLedger::home($tenant);
        $loc      = trim((string) ($this->option('location') ?: '')) ?: $home;
        $atHome   = ($loc === $home);
        $onLedger = StockLedger::readsFromLedger($tenant);
        $ledger   = StockLedger::forTenant($tenant);
        $this->line('stock home: ' . $home . '   counting at: ' . $loc
            . '   reads from ledger: ' . ($onLedger ? 'yes' : 'no'));
        if (! $atHome) {
            // v5.07.1 — a count filed away from home must NOT rewrite products.stock. That column
            // describes the HOME warehouse, and syncProductStockCache() recomputes it from the home
            // ledger; writing the count into it and then syncing would overwrite the count with a
            // different warehouse's total. So away-from-home counts land on the ledger only.
            $this->warn('Counting AWAY from the home warehouse: the ledger records it, products.stock is left alone');
        }
        $this->line('');

        $rows = []; $plan = []; $blocked = 0;
        foreach ($lines as [$needle, $qty]) {
            $cands = $this->resolve($tid, $needle);

            if ($cands->count() !== 1) {
                $blocked++;
                $this->line('<fg=yellow>' . str_pad('"' . $needle . '"', 26) . ' counted ' . $this->q($qty)
                    . '  — ' . ($cands->isEmpty() ? 'NO PRODUCT MATCHES' : $cands->count() . ' PRODUCTS MATCH, so nothing is guessed') . '</>');
                foreach ($cands as $c) {
                    $this->line('      re-run with  "#' . $c->id . '=' . $this->q($qty) . '"   '
                        . str_pad(mb_substr((string) $c->name, 0, 46), 48)
                        . ' documents say ' . $this->q((float) ($bought[$c->id] ?? 0) - (float) ($sold[$c->id] ?? 0)));
                }
                continue;
            }

            $p   = $cands->first();
            $b   = (float) ($bought[$p->id] ?? 0);
            $s   = (float) ($sold[$p->id] ?? 0);
            $doc = round($b - $s, 3);
            $plan[] = ['p' => $p, 'counted' => $qty, 'doc' => $doc];
            $rows[] = [
                mb_substr((string) $p->name, 0, 40), $this->q($b), $this->q($s), $this->q($doc),
                $this->q($qty), ($qty - $doc > 0 ? '+' : '') . $this->q($qty - $doc), $this->q((float) ($p->stock ?? 0)),
            ];
        }

        if ($rows) {
            $this->table(['Product', 'Purchased', 'Sold', 'Documents', 'Counted', 'Variance', 'products.stock'], $rows);
        }
        $this->line('resolved ' . count($plan) . ' of ' . count($lines) . ' line(s)'
            . ($blocked ? ('   ' . $blocked . ' need you to pick the product') : ''));

        if (! $write) {
            $this->line('');
            $this->comment('Dry run — nothing written. A large variance is a question about the paperwork,');
            $this->comment('not something to smooth over. Juba Stock will NOT change: it reports the documents,');
            $this->comment('and having both numbers is the point. Re-run with --write to record the count.');

            return self::SUCCESS;
        }

        $done = 0; $skipped = 0;
        foreach ($plan as $row) {
            $p = $row['p'];
            $already = DB::table('stock_movements')->where('tenant_id', $tid)
                ->where('product_id', $p->id)->where('ref_type', 'trust_count')
                ->whereDate('created_at', $date)->exists();
            if ($already) { $skipped++; $this->line('  SKIP (already counted on ' . $date . '): ' . $p->name); continue; }

            $onHand = ($onLedger || ! $atHome)
                ? (float) DB::table('stock_movements')->where('tenant_id', $tid)
                    ->where('product_id', $p->id)->where('location', $loc)->sum('qty')
                : (float) ($p->stock ?? 0);
            $diff = round($row['counted'] - $onHand, 3);

            DB::transaction(function () use ($ledger, $p, $loc, $diff, $row, $onHand, $by, $tid, $onLedger, $atHome) {
                if (abs($diff) > 0.0005) {
                    $ledger->adjust([
                        'product_id' => (int) $p->id, 'location' => $loc, 'qty' => $diff,
                        'ref_type' => 'trust_count', 'ref_id' => null,
                        'note' => 'physical count ' . $row['counted'] . ' vs book ' . $onHand
                                . ' (documents say ' . $row['doc'] . ')',
                        'created_by' => $by,
                    ]);
                }
                if (! $atHome) {
                    return;                      // the ledger holds it; products.stock is home's
                }
                if ($onLedger) {
                    // The ledger is the authority here, and the adjustment above already made it
                    // equal the count. Sync FIRST, then stamp - the other way round the sync
                    // overwrote the number we had just written.
                    $ledger->syncProductStockCache((int) $p->id);
                    Product::where('tenant_id', $tid)->where('id', $p->id)->update([
                        'stock_confidence' => 'exact', 'stock_checked_at' => now(), 'stock_source' => 'count',
                    ]);
                } else {
                    Product::where('tenant_id', $tid)->where('id', $p->id)->update([
                        'stock' => (int) round($row['counted']),
                        'stock_confidence' => 'exact', 'stock_checked_at' => now(), 'stock_source' => 'count',
                    ]);
                }
            });

            $done++;
            $this->line('  RECORDED  ' . str_pad(mb_substr((string) $p->name, 0, 46), 48)
                . ' count ' . $this->q($row['counted']) . '  ledger ' . ($diff > 0 ? '+' : '') . $this->q($diff));
        }

        $this->line('');
        $this->info($done . ' recorded' . ($skipped ? (', ' . $skipped . ' skipped as already counted') : '')
            . '. Juba Stock is unchanged and still reports Purchased − Sold.');

        return self::SUCCESS;
    }

    /** Exactly one product, or every candidate so a person can choose. Never a guess. */
    private function resolve(int $tid, string $needle)
    {
        if (str_starts_with($needle, '#')) {
            return Product::where('tenant_id', $tid)->where('id', (int) ltrim($needle, '#'))->get(['id', 'name', 'stock']);
        }

        return Product::where('tenant_id', $tid)->where('active', true)
            ->where(function ($w) use ($needle) {
                $w->where('name', 'ilike', '%' . $needle . '%')
                  ->orWhere('keywords', 'ilike', '%' . $needle . '%');
            })->orderBy('name')->get(['id', 'name', 'stock']);
    }

    /**
     * Purchased and sold per product, by the SAME rules as JubaStockCapability — purchase lines for
     * the one, live invoices plus never-invoiced orders for the other. Read-only; it is here so the
     * variance is measured against what the screen actually says, not against a second opinion.
     *
     * @return array{0:array<int,float>,1:array<int,float>}
     */
    private function documents(int $tid): array
    {
        $bought = DB::table('order_costs')->where('tenant_id', $tid)->where('stage', 'purchase')
            ->whereNull('voided_at')->whereNotNull('product_id')->where('qty', '>', 0)
            ->groupBy('product_id')->selectRaw('product_id, sum(qty) q')->pluck('q', 'product_id')->all();

        $byName = [];
        $claim = function ($raw, $pid) use (&$byName) {
            $k = PurchaseNameMatch::key((string) $raw);
            if ($k === '') return;
            $byName[$k] = (array_key_exists($k, $byName) && $byName[$k] !== $pid) ? null : $pid;
        };
        foreach (Product::where('tenant_id', $tid)->get(['id', 'name', 'keywords']) as $p) {
            $claim($p->name, (int) $p->id);
            $kw = (string) ($p->keywords ?? '');
            if (preg_match('/\R/u', $kw)) {
                foreach (preg_split('/\R/u', $kw) ?: [] as $a) { $claim($a, (int) $p->id); }
            } else {
                foreach (PurchaseNameMatch::aliases($kw) as $a) { $claim($a, (int) $p->id); }
                $claim($kw, (int) $p->id);
            }
        }
        $norm = fn ($n) => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $n))));

        $sold = []; $invoiced = [];
        foreach (DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.tenant_id', $tid)->where('invoices.status', '!=', 'void')
            ->get(['invoice_items.name', 'invoice_items.qty', 'invoices.order_id']) as $r) {
            if ($r->order_id) { $invoiced[(int) $r->order_id] = true; }
            $q = (float) $r->qty;
            if ($q <= 0) continue;
            $pid = $byName[$norm($r->name)] ?? null;
            if ($pid) { $sold[$pid] = ($sold[$pid] ?? 0) + $q; }
        }
        foreach (DB::table('orders')->where('tenant_id', $tid)
            ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','canceled','rejected','void')")
            ->get(['id', 'items_json']) as $o) {
            if (isset($invoiced[(int) $o->id])) continue;
            $ij = is_string($o->items_json) ? json_decode($o->items_json, true) : $o->items_json;
            foreach ((is_array($ij) ? $ij : []) as $it) {
                if (! is_array($it)) continue;
                $q = (float) ($it['qty'] ?? 0);
                if ($q <= 0) continue;
                $pid = (int) ($it['product_id'] ?? ($it['id'] ?? 0));
                if ($pid <= 0) { $pid = (int) ($byName[$norm($it['name'] ?? '')] ?? 0); }
                if ($pid > 0) { $sold[$pid] = ($sold[$pid] ?? 0) + $q; }
            }
        }

        return [$bought, $sold];
    }

    private function q(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.') ?: '0';
    }
}
