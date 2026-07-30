<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Trust\TrustNotifier;
use App\Services\Trust\TrustRuntime;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Trust — weekly owner report + pilot metrics engine (Pilot Plan §5).
 *
 * One command computes, every week, the numbers the whole pilot is judged on:
 *   M1  shrink % of sales (count-revealed "unknown" vs declared write-offs vs confirmed losses)
 *   M3  attribution: how much loss value is tied to a stage (movement type) + person
 *   M5  queue precision: % of resolved investigations that found something real
 *   M6  evidence coverage: % of received value carrying passive/counterparty evidence
 *   M9  Verified Stock Value %: on-hand value at record confidence ≥ 0.9
 * …and sends the owner the one chart that sells the product: the shrink waterfall.
 *
 *   php artisan trust:weekly --tenant=17 [--days=7] [--send] [--json]
 *
 * Schedule weekly (Mon 07:15 EAT) — see the console append file. --json prints a machine-readable
 * block; archive one per week and the week-20 gate review is a diff, not an argument.
 */
final class TrustWeeklyCommand extends Command
{
    protected $signature = 'trust:weekly
        {--tenant= : tenant id (required)}
        {--days=7 : reporting window}
        {--send : WhatsApp the report to owner numbers}
        {--json : print metrics as JSON (archive these for the gate review)}';

    protected $description = 'Weekly shrink waterfall + verified-stock report; computes the pilot gate metrics.';

    public function handle(TenantContext $ctx): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $tenant = Tenant::findOrFail($id);
        $ctx->set($id);
        $days = max(1, (int) $this->option('days'));
        $from = now()->subDays($days);

        $rt = TrustRuntime::forTenant($tenant);
        $unit = $rt->unitValues();
        $val = fn ($productId, $qty) => abs((float) $qty) * ($unit[$productId] ?? 0.0);

        // ---- sales base (OUT value in window) ----
        $sales = 0.0;
        foreach (DB::table('stock_movements')->where('tenant_id', $id)
            ->where('type', 'OUT')->where('created_at', '>=', $from)
            ->get(['product_id', 'qty']) as $m) {
            $sales += $val($m->product_id, $m->qty);
        }

        // ---- shrink waterfall ----
        // 1) revealed by blind counts (the former "unknown" — now surfaced and dated)
        $countRevealed = 0.0;
        // 2) declared write-offs (negative ADJUST not from counts)
        $declared = 0.0;
        foreach (DB::table('stock_movements')->where('tenant_id', $id)
            ->where('type', 'ADJUST')->where('qty', '<', 0)
            ->where('created_at', '>=', $from)
            ->get(['product_id', 'qty', 'ref_type']) as $m) {
            $v = $val($m->product_id, $m->qty);
            if ($m->ref_type === 'trust_count') {
                $countRevealed += $v;
            } else {
                $declared += $v;
            }
        }
        // 3) human-confirmed losses from the queue, attributed by stage (movement type)
        $confirmed = ['IN' => 0.0, 'OUT' => 0.0, 'MOVE' => 0.0, 'ADJUST' => 0.0];
        $confRows = DB::table('trust_investigations')
            ->join('stock_movements', 'stock_movements.id', '=', 'trust_investigations.stock_movement_id')
            ->where('trust_investigations.tenant_id', $id)
            ->where('trust_investigations.outcome', 'confirmed_loss')
            ->where('trust_investigations.resolved_at', '>=', $from)
            ->get(['trust_investigations.outcome_value', 'trust_investigations.expected_loss',
                   'stock_movements.type']);
        foreach ($confRows as $r) {
            $confirmed[strtoupper((string) $r->type)] =
                ($confirmed[strtoupper((string) $r->type)] ?? 0.0)
                + (float) ($r->outcome_value ?? $r->expected_loss ?? 0);
        }
        $confirmedTotal = array_sum($confirmed);
        $shrinkTotal = $countRevealed + $declared + $confirmedTotal;
        $shrinkPct = $sales > 0 ? 100.0 * $shrinkTotal / $sales : 0.0;

        // ---- M3 attribution: loss value tied to a stage and an actor ----
        $attributed = $confirmedTotal;   // human-confirmed is attributed by definition
        // variance events carry the actor window — count them attributed when a window actor exists
        $varWithActors = DB::table('trust_variance_events as ve')
            ->where('ve.tenant_id', $id)->where('ve.created_at', '>=', $from)->get();
        $varAttr = 0.0;
        foreach ($varWithActors as $ve) {
            $hasActor = DB::table('stock_movements')
                ->where('tenant_id', $id)
                ->where(function ($w) use ($ve) {
                    $ve->sku ? $w->where('sku', $ve->sku) : $w->where('product_id', $ve->product_id);
                })
                ->where('location', $ve->location)
                ->whereBetween('created_at', [$ve->window_start, $ve->window_end])
                ->whereNotNull('created_by')->exists();
            if ($hasActor) {
                $varAttr += abs((float) $ve->variance_value);
            }
        }
        $attributed += $varAttr;
        $attrPct = $shrinkTotal > 0 ? min(100.0, 100.0 * $attributed / $shrinkTotal) : 100.0;

        // ---- M5 queue precision ----
        $resolved = DB::table('trust_investigations')->where('tenant_id', $id)
            ->whereNotNull('outcome')->where('resolved_at', '>=', $from)
            ->get(['outcome']);
        $hits = $resolved->whereIn('outcome', ['error', 'confirmed_loss'])->count();
        $precision = $resolved->count() > 0 ? 100.0 * $hits / $resolved->count() : null;
        $openQueue = DB::table('trust_investigations')->where('tenant_id', $id)
            ->whereNull('outcome')->count();

        // ---- M6 evidence coverage on received value ----
        $inValue = 0.0;
        $coveredValue = 0.0;
        $ins = DB::table('stock_movements')->where('tenant_id', $id)
            ->where('type', 'IN')->where('created_at', '>=', $from)
            ->get(['id', 'product_id', 'qty']);
        if ($ins->count()) {
            $covered = DB::table('trust_evidence_items')->where('tenant_id', $id)
                ->whereIn('stock_movement_id', $ins->pluck('id'))
                ->whereIn('signal_code', ['scale_weight', 'grn_photo', 'dock_clip', 'counterparty_ack'])
                ->where('status', 'present_pass')
                ->pluck('stock_movement_id')->unique()->flip();
            foreach ($ins as $m) {
                $v = $val($m->product_id, $m->qty);
                $inValue += $v;
                if (isset($covered[$m->id])) {
                    $coveredValue += $v;
                }
            }
        }
        $coverage = $inValue > 0 ? 100.0 * $coveredValue / $inValue : null;

        // ---- M9 Verified Stock Value % ----
        $onHand = DB::table('stock_movements')->where('tenant_id', $id)
            ->whereNotNull('product_id')
            ->select('product_id', 'location', DB::raw('SUM(qty) as q'))
            ->groupBy('product_id', 'location')->having(DB::raw('SUM(qty)'), '>', 0)->get();
        $conf = [];
        foreach (DB::table('trust_record_confidence')->where('tenant_id', $id)->get() as $rc) {
            $conf[$rc->product_id . '|' . $rc->location] = (float) $rc->confidence;
        }
        $stockValue = 0.0;
        $verifiedValue = 0.0;
        foreach ($onHand as $r) {
            $v = (float) $r->q * ($unit[$r->product_id] ?? 0.0);
            $stockValue += $v;
            if (($conf[$r->product_id . '|' . $r->location] ?? 0.0) >= 0.9) {
                $verifiedValue += $v;
            }
        }
        $verifiedPct = $stockValue > 0 ? 100.0 * $verifiedValue / $stockValue : null;

        // ---- repeat-variance SKUs ----
        $repeat = DB::table('trust_variance_events')->where('tenant_id', $id)
            ->where('created_at', '>=', now()->subDays(28))
            ->select('sku', 'product_id', 'location',
                DB::raw('COUNT(*) as n'), DB::raw('SUM(ABS(variance_value)) as v'))
            ->groupBy('sku', 'product_id', 'location')->having(DB::raw('COUNT(*)'), '>=', 2)
            ->orderByDesc(DB::raw('SUM(ABS(variance_value))'))->limit(5)->get();
        $names = Product::where('tenant_id', $id)->pluck('name', 'id');

        // ---- output ----
        $metrics = [
            'tenant' => $tenant->name, 'window_days' => $days, 'as_of' => now()->toDateString(),
            'sales_value' => round($sales, 2),
            'shrink_total' => round($shrinkTotal, 2),
            'shrink_pct_of_sales' => round($shrinkPct, 3),
            'shrink_breakdown' => [
                'revealed_by_counts' => round($countRevealed, 2),
                'declared_write_offs' => round($declared, 2),
                'confirmed_receiving' => round($confirmed['IN'], 2),
                'confirmed_sales_floor' => round($confirmed['OUT'], 2),
                'confirmed_transfers' => round($confirmed['MOVE'], 2),
                'confirmed_adjustments' => round($confirmed['ADJUST'], 2),
            ],
            'M3_attribution_pct' => round($attrPct, 1),
            'M5_queue_precision_pct' => $precision !== null ? round($precision, 1) : null,
            'M5_resolved' => $resolved->count(), 'M5_open' => $openQueue,
            'M6_evidence_coverage_pct' => $coverage !== null ? round($coverage, 1) : null,
            'M9_verified_stock_pct' => $verifiedPct !== null ? round($verifiedPct, 1) : null,
            'stock_value' => round($stockValue, 2),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT));
        }

        $pct = fn ($x, $fallback = '—') => $x === null ? $fallback : (round($x) . '%');
        $l = [];
        $l[] = '🛡 *Inventory Trust — weekly* (' . $tenant->name . ', last ' . $days . 'd)';
        $l[] = '*Verified stock: ' . $pct($verifiedPct) . '* of value · Shrink: '
             . number_format($shrinkTotal) . ' (' . round($shrinkPct, 2) . '% of sales)';
        if ($shrinkTotal > 0) {
            $l[] = '';
            $l[] = '*Where it went:*';
            $bd = [
                ['Revealed by counts', $countRevealed],
                ['Declared write-offs', $declared],
                ['Receiving (confirmed)', $confirmed['IN']],
                ['Sales floor / till (confirmed)', $confirmed['OUT']],
                ['Transfers (confirmed)', $confirmed['MOVE']],
            ];
            foreach ($bd as [$label, $v]) {
                if ($v > 0) {
                    $l[] = sprintf('· %s: %s (%d%%)', $label, number_format($v),
                        (int) round(100 * $v / $shrinkTotal));
                }
            }
            $l[] = 'Attributed to a stage/person: *' . round($attrPct) . '%*';
        } else {
            $l[] = '✅ No shrink recorded this week.';
        }
        $l[] = '';
        $l[] = 'Queue: ' . $resolved->count() . ' resolved (' . $pct($precision, 'n/a')
             . ' found something real), ' . $openQueue . ' open.';
        if ($coverage !== null) {
            $l[] = 'Receiving evidence coverage: ' . $pct($coverage) . ' of received value.';
        }
        if ($repeat->count()) {
            $l[] = '';
            $l[] = '*Repeat-variance items (28d)* — watch these:';
            foreach ($repeat as $r) {
                $l[] = sprintf('· %s @ %s — %d× , %s',
                    $r->sku ?: (string) ($names[$r->product_id] ?? ('#' . $r->product_id)),
                    $r->location, $r->n, number_format((float) $r->v));
            }
        }
        $text = implode("\n", $l);
        $this->line($text);

        if ($this->option('send')) {
            $sent = TrustNotifier::sendOwners($tenant, $text);
            $this->info($sent ? "Sent to {$sent} number(s)." : 'No WhatsApp instance/numbers configured.');
        }
        return self::SUCCESS;
    }
}
