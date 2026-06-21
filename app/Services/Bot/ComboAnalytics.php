<?php

namespace App\Services\Bot;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Records combo impressions (shown) and conversions (accepted), and produces the
 * "most successful combos" summary with conversion %. All methods are defensive —
 * analytics must never break the customer reply.
 */
class ComboAnalytics
{
    /** Log that we showed these recommended products for a source, in a context. */
    public function recordImpressions(int $tenantId, ?int $sourceId, array $recommendedIds, string $context): void
    {
        $recommendedIds = array_values(array_unique(array_filter(array_map('intval', $recommendedIds), fn ($x) => $x > 0)));
        if ($tenantId <= 0 || ! $recommendedIds) return;

        $now  = now();
        $rows = [];
        foreach ($recommendedIds as $rid) {
            $rows[] = [
                'tenant_id'              => $tenantId,
                'source_product_id'      => $sourceId ?: null,
                'recommended_product_id' => $rid,
                'context'                => in_array($context, ['single_product', 'after_add', 'checkout'], true) ? $context : 'single_product',
                'created_at'             => $now,
            ];
        }
        try { DB::table('combo_impressions')->insert($rows); } catch (\Throwable $e) {}
    }

    /** Log that the customer accepted (added) a previously-recommended product. */
    public function recordConversion(int $tenantId, ?int $sourceId, int $recommendedId): void
    {
        if ($tenantId <= 0 || $recommendedId <= 0) return;
        try {
            DB::table('combo_conversions')->insert([
                'tenant_id'              => $tenantId,
                'source_product_id'      => $sourceId ?: null,
                'recommended_product_id' => $recommendedId,
                'created_at'             => now(),
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * Per-pair performance: [['source','recommended','shown','accepted','conv_pct'], ...]
     * ordered by accepted desc then conversion desc. For the dashboard.
     */
    public function summary(int $tenantId, int $limit = 25): array
    {
        if ($tenantId <= 0) return [];

        try {
            $imp = DB::table('combo_impressions')->where('tenant_id', $tenantId)
                ->selectRaw('source_product_id as src, recommended_product_id as rec, COUNT(*) as shown')
                ->groupBy('source_product_id', 'recommended_product_id')->get();

            $conv = DB::table('combo_conversions')->where('tenant_id', $tenantId)
                ->selectRaw('source_product_id as src, recommended_product_id as rec, COUNT(*) as accepted')
                ->groupBy('source_product_id', 'recommended_product_id')->get();
        } catch (\Throwable $e) {
            return [];
        }

        $accepted = [];
        foreach ($conv as $c) $accepted[$this->key($c->src, $c->rec)] = (int) $c->accepted;

        // Collect product ids for name resolution.
        $ids = [];
        foreach ($imp as $r) { if ($r->src) $ids[(int) $r->src] = true; $ids[(int) $r->rec] = true; }
        $names = Product::whereIn('id', array_keys($ids))->pluck('name', 'id');

        $rows = [];
        foreach ($imp as $r) {
            $shown = (int) $r->shown;
            $acc   = $accepted[$this->key($r->src, $r->rec)] ?? 0;
            $rows[] = [
                'source'      => $r->src ? (string) ($names[(int) $r->src] ?? ('#' . $r->src)) : 'Cart',
                'recommended' => (string) ($names[(int) $r->rec] ?? ('#' . $r->rec)),
                'shown'       => $shown,
                'accepted'    => $acc,
                'conv_pct'    => $shown > 0 ? round($acc * 100 / $shown, 1) : 0.0,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['accepted'] <=> $a['accepted']) ?: ($b['conv_pct'] <=> $a['conv_pct']));
        return array_slice($rows, 0, $limit);
    }

    private function key($src, $rec): string
    {
        return ((int) $src) . ':' . ((int) $rec);
    }
}
