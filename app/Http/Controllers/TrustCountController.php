<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use App\Services\Trust\TrustNotifier;
use App\Services\Trust\TrustRuntime;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Trust — blind cycle count (Phase B1). Public nonce page.
 *
 * The counter sees product names ONLY — never the expected on-hand (blind counting: an honest
 * count can't anchor on the book number, a dishonest one can't type the book number back).
 * Submit compares each count against the system of record (ledger projection when the tenant is
 * on ledger reads, products.stock for Kampala otherwise), books signed ADJUSTs for differences
 * (ref_type=trust_count), resets record confidence, and WhatsApps the owner the variance list.
 * Negative adjustments automatically flow through the variance machinery (window actors taxed).
 */
class TrustCountController extends Controller
{
    private function session(string $nonce): ?object
    {
        return DB::table('trust_count_sessions')->where('nonce', $nonce)->first();
    }

    public function show(string $nonce, TenantContext $ctx)
    {
        $s = $this->session($nonce);
        if (! $s) {
            abort(404);
        }
        $ctx->set((int) $s->tenant_id);
        return response()->view('trust-count', [
            's' => $s, 'nonce' => $nonce,
            'picks' => json_decode((string) $s->picks, true) ?: [],
            'results' => json_decode((string) ($s->results ?? ''), true) ?: [],
        ]);
    }

    public function submit(string $nonce, Request $r, TenantContext $ctx)
    {
        $s = $this->session($nonce);
        if (! $s) {
            abort(404);
        }
        if ($s->status === 'submitted') {
            return response()->json(['ok' => false, 'error' => 'Already submitted.'], 409);
        }
        $ctx->set((int) $s->tenant_id);
        $tenant = Tenant::findOrFail((int) $s->tenant_id);

        $data = $r->validate([
            'counted_by' => 'required|string|max:80',
            'results' => 'required|array|min:1|max:40',
            'results.*.product_id' => 'required|integer',
            'results.*.location' => 'required|string|max:40',
            'results.*.counted' => 'required|numeric|min:0',
        ]);

        $picks = collect(json_decode((string) $s->picks, true) ?: []);
        $ledger = StockLedger::forTenant($tenant);
        $rt = TrustRuntime::forTenant($tenant);
        $onLedger = StockLedger::readsFromLedger($tenant);
        $names = Product::where('tenant_id', $tenant->id)
            ->whereIn('id', array_column($data['results'], 'product_id'))->pluck('name', 'id');

        $out = [];
        DB::transaction(function () use ($s, $data, $tenant, $picks, $ledger, $rt, $onLedger, $names, &$out) {
            foreach ($data['results'] as $res) {
                $pid = (int) $res['product_id'];
                $loc = (string) $res['location'];
                if (! $picks->contains(fn ($p) => (int) $p['product_id'] === $pid && $p['location'] === $loc)) {
                    continue;   // only items the engine picked for this session
                }
                $counted = (float) $res['counted'];

                if ($onLedger) {
                    $onHand = (float) DB::table('stock_movements')
                        ->where('tenant_id', $tenant->id)->where('product_id', $pid)
                        ->where('location', $loc)->sum('qty');
                } else {
                    $onHand = $loc === StockLedger::LOC_KAMPALA
                        ? (float) (Product::where('tenant_id', $tenant->id)->where('id', $pid)->value('stock') ?? 0)
                        : (float) DB::table('stock_movements')
                            ->where('tenant_id', $tenant->id)->where('product_id', $pid)
                            ->where('location', $loc)->sum('qty');
                }

                $diff = round($counted - $onHand, 3);
                $row = ['product_id' => $pid, 'location' => $loc, 'name' => (string) ($names[$pid] ?? ('#' . $pid)),
                        'counted' => $counted, 'on_hand' => $onHand, 'diff' => $diff];

                if (abs($diff) > 0.0005) {
                    $m = $ledger->adjust([
                        'product_id' => $pid, 'location' => $loc, 'qty' => $diff,
                        'ref_type' => 'trust_count', 'ref_id' => $s->id,
                        'note' => 'blind count ' . $counted . ' vs book ' . $onHand,
                        'created_by' => (string) $data['counted_by'],
                    ]);
                    DB::table('trust_evidence_items')->insert([
                        'tenant_id' => $tenant->id, 'stock_movement_id' => $m->id,
                        'signal_code' => 'cycle_count_match', 'status' => 'present_pass',
                        'payload' => json_encode(['counted' => $counted, 'book' => $onHand]),
                        'captured_by' => (string) $data['counted_by'],
                        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $score = $rt->scoreMovement($m->fresh());
                    $row['trust'] = $score['trust'];

                    if ($onLedger) {
                        $ledger->syncProductStockCache($pid);
                    } elseif ($loc === StockLedger::LOC_KAMPALA) {
                        Product::where('tenant_id', $tenant->id)->where('id', $pid)
                            ->update(['stock' => (int) round($counted),
                                      'stock_confidence' => 'exact',
                                      'stock_checked_at' => now(), 'stock_source' => 'count']);
                    }
                }

                $matchQuality = $onHand > 0 ? max(0.0, 1.0 - min(1.0, abs($diff) / max($onHand, 1.0))) : ($diff == 0.0 ? 1.0 : 0.5);
                $rt->registerCount($pid, $loc, max(0.5, $matchQuality));
                $out[] = $row;
            }

            DB::table('trust_count_sessions')->where('id', $s->id)->update([
                'status' => 'submitted', 'submitted_by' => (string) $data['counted_by'],
                'submitted_at' => now(), 'results' => json_encode($out), 'updated_at' => now(),
            ]);
        });

        $withDiff = array_filter($out, fn ($x) => abs($x['diff']) > 0.0005);
        $txt = ['🔢 *Blind count* by ' . $data['counted_by'] . ' — ' . count($out) . ' items'];
        if ($withDiff) {
            foreach ($withDiff as $x) {
                $txt[] = sprintf('· %s @ %s: counted %s vs book %s → *%+g*',
                    $x['name'], $x['location'],
                    rtrim(rtrim((string) $x['counted'], '0'), '.'),
                    rtrim(rtrim((string) $x['on_hand'], '0'), '.'), $x['diff']);
            }
            $txt[] = '_Adjustments booked; negative variances entered the trust engine._';
        } else {
            $txt[] = '✅ All counts matched the book. Confidence reset.';
        }
        TrustNotifier::sendOwners($tenant, implode("\n", $txt));

        return response()->json(['ok' => true, 'results' => $out]);
    }
}
