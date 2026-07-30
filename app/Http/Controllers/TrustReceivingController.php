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
 * Inventory Trust — receiving flow (Phase B1). Public nonce page (TaskEngine pattern).
 *
 * The receiver records WHAT ARRIVED — count, weight, photo per line — with no expected quantities
 * shown anywhere (blind receiving: the strongest single control in the system). Submit writes real
 * StockLedger IN movements with ref_type=trust_receiving, attaches scale_weight / grn_photo
 * evidence rows, scores each movement, and WhatsApps the owner a summary that names every
 * evidence gap — BEFORE the driver has left.
 *
 * Legacy stock cache: if the tenant reads on-hand from the ledger (stock_v2 + stock_ledger), the
 * products.stock cache is re-derived (E6.5). Otherwise products.stock is incremented directly for
 * the Kampala location so pre-cutover on-hand stays truthful (mirrors E6.2 dual-write intent).
 */
class TrustReceivingController extends Controller
{
    private function session(string $nonce): ?object
    {
        return DB::table('trust_receiving_sessions')->where('nonce', $nonce)->first();
    }

    public function show(string $nonce, TenantContext $ctx)
    {
        $s = $this->session($nonce);
        if (! $s) {
            abort(404);
        }
        $ctx->set((int) $s->tenant_id);
        $lines = $s->status === 'submitted'
            ? DB::table('trust_receiving_lines')->where('session_id', $s->id)->get()
            : collect();
        return response()->view('trust-receiving', ['s' => $s, 'lines' => $lines, 'nonce' => $nonce]);
    }

    /** Tenant-scoped product search for the line picker. */
    public function products(string $nonce, Request $r, TenantContext $ctx)
    {
        $s = $this->session($nonce);
        if (! $s) {
            abort(404);
        }
        $ctx->set((int) $s->tenant_id);
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $rows = Product::where('tenant_id', $s->tenant_id)
            ->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                  ->orWhere('sku', 'ilike', "%{$q}%")
                  ->orWhere('barcode', 'ilike', "%{$q}%");
            })
            ->orderBy('name')->limit(10)
            ->get(['id', 'name', 'sku', 'unit_label', 'sold_by_weight']);
        return response()->json($rows);
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
            'received_by' => 'required|string|max:80',
            'supplier_name' => 'nullable|string|max:160',
            'expected_ref' => 'nullable|string|max:120',
            'lines' => 'required|array|min:1|max:60',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.001',
            'lines.*.weight_kg' => 'nullable|numeric|min:0',
            'lines.*.photo_data' => 'nullable|string',
            'lines.*.note' => 'nullable|string|max:200',
        ]);

        $ledger = StockLedger::forTenant($tenant);
        $rt = TrustRuntime::forTenant($tenant);
        $names = Product::where('tenant_id', $tenant->id)
            ->whereIn('id', array_column($data['lines'], 'product_id'))
            ->pluck('name', 'id');

        $results = [];
        DB::transaction(function () use ($s, $data, $tenant, $ledger, $rt, $names, &$results) {
            foreach ($data['lines'] as $ln) {
                $photoUrl = ! empty($ln['photo_data'])
                    ? TrustNotifier::storePhoto($tenant->id, (string) $ln['photo_data'])
                    : null;

                $lineId = DB::table('trust_receiving_lines')->insertGetId([
                    'tenant_id' => $tenant->id, 'session_id' => $s->id,
                    'product_id' => (int) $ln['product_id'], 'qty' => (float) $ln['qty'],
                    'weight_kg' => isset($ln['weight_kg']) && $ln['weight_kg'] !== null ? (float) $ln['weight_kg'] : null,
                    'photo_url' => $photoUrl, 'note' => (string) ($ln['note'] ?? ''),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $m = $ledger->receive([
                    'product_id' => (int) $ln['product_id'],
                    'location' => (string) $s->location,
                    'qty' => (float) $ln['qty'],
                    'ref_type' => 'trust_receiving', 'ref_id' => $s->id,
                    'note' => trim(($data['supplier_name'] ?? $s->supplier_name ?? '') . ' ' . ($ln['note'] ?? '')),
                    'created_by' => (string) $data['received_by'],
                ]);
                DB::table('trust_receiving_lines')->where('id', $lineId)
                    ->update(['stock_movement_id' => $m->id]);

                // evidence rows (explicit, so the audit trail shows what was and wasn't provided)
                $ev = [
                    ['scale_weight', ! empty($ln['weight_kg']) ? 'present_pass' : 'expected_missing',
                     ! empty($ln['weight_kg']) ? ['weight_kg' => (float) $ln['weight_kg']] : null],
                    ['grn_photo', $photoUrl ? 'present_pass' : 'expected_missing',
                     $photoUrl ? ['url' => $photoUrl] : null],
                ];
                foreach ($ev as [$code, $status, $payload]) {
                    DB::table('trust_evidence_items')->insert([
                        'tenant_id' => $tenant->id, 'stock_movement_id' => $m->id,
                        'signal_code' => $code, 'status' => $status,
                        'payload' => $payload ? json_encode($payload) : null,
                        'captured_by' => (string) $data['received_by'],
                        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $score = $rt->scoreMovement($m->fresh());

                // legacy on-hand truthfulness (see class docblock)
                if (StockLedger::readsFromLedger($tenant)) {
                    $ledger->syncProductStockCache((int) $ln['product_id']);
                } elseif ($s->location === StockLedger::LOC_KAMPALA) {
                    Product::where('tenant_id', $tenant->id)->where('id', (int) $ln['product_id'])
                        ->increment('stock', (int) round((float) $ln['qty']));
                }

                $results[] = ['name' => (string) ($names[$ln['product_id']] ?? ('#' . $ln['product_id'])),
                              'qty' => (float) $ln['qty'], 'trust' => $score['trust'],
                              'decision' => $score['decision'], 'value' => $score['value'],
                              'weight' => ! empty($ln['weight_kg']), 'photo' => (bool) $photoUrl];
            }

            DB::table('trust_receiving_sessions')->where('id', $s->id)->update([
                'status' => 'submitted', 'submitted_by' => (string) $data['received_by'],
                'supplier_name' => (string) ($data['supplier_name'] ?? $s->supplier_name ?? ''),
                'expected_ref' => (string) ($data['expected_ref'] ?? ''),
                'submitted_at' => now(), 'summary' => json_encode($results), 'updated_at' => now(),
            ]);
        });

        // owner summary — sent while the driver is still at the gate
        $flags = array_filter($results, fn ($x) => $x['decision'] !== 'auto_post' || ! $x['weight'] || ! $x['photo']);
        $txt = ['📦 *Goods received* — ' . ($data['supplier_name'] ?? $s->supplier_name ?: 'supplier?')
                . ' @ ' . $s->location . ' by ' . $data['received_by']];
        $txt[] = count($results) . ' lines, value ~' . number_format(array_sum(array_column($results, 'value')));
        foreach ($results as $x) {
            $gap = (! $x['weight'] ? ' ⚠no-scale' : '') . (! $x['photo'] ? ' ⚠no-photo' : '');
            $txt[] = sprintf('· %s ×%s — T %s%s%s', $x['name'], rtrim(rtrim((string) $x['qty'], '0'), '.'),
                $x['trust'], $x['decision'] !== 'auto_post' ? ' *' . strtoupper($x['decision']) . '*' : '', $gap);
        }
        if ($flags) {
            $txt[] = '_Review the ⚠ lines before signing the delivery note._';
        }
        TrustNotifier::sendOwners($tenant, implode("\n", $txt));

        return response()->json(['ok' => true, 'lines' => count($results)]);
    }
}
