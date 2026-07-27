<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Storefront Insights — reads the storefront behaviour log (product_events) and the search log
 * (search_events) back into the panel as the highest-ROI reports:
 *   • Product funnel   — views → add-to-cart → checkout, per product, with conversion
 *   • Top searches     — what shoppers look for (and whether it returns anything)
 *   • Search gaps      — searches that returned NOTHING (unmet demand: stock it / add keywords)
 *
 * Read-only projection over the last 30 days. Writes nothing, touches no frozen service. The web
 * signal fills in as the storefront event tracking accrues; bot search history shows immediately.
 */
final class StorefrontInsightsCapability implements ProcessCapability
{
    public function key(): string { return 'storefront.insights'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $since = now()->subDays(30);

        // ---- Product funnel (product_events: view | add | checkout) ----
        $ev = $this->safe(fn () => DB::table('product_events')
            ->where('tenant_id', $t)->where('created_at', '>=', $since)
            ->whereNotNull('product_id')
            ->select('product_id',
                DB::raw("sum(case when event = 'view' then 1 else 0 end) as views"),
                DB::raw("sum(case when event = 'add' then 1 else 0 end) as adds"),
                DB::raw("sum(case when event = 'checkout' then 1 else 0 end) as checkouts"))
            ->groupBy('product_id')->get());

        $totV = 0; $totA = 0; $totC = 0; $ids = [];
        foreach ($ev as $r) { $totV += (int) $r->views; $totA += (int) $r->adds; $totC += (int) $r->checkouts; $ids[] = (int) $r->product_id; }
        $names = $ids ? Product::where('tenant_id', $t)->whereIn('id', $ids)->pluck('name', 'id')->all() : [];

        $evArr = $ev->all();
        usort($evArr, fn ($a, $b) => [(int) $b->views, (int) $b->adds] <=> [(int) $a->views, (int) $a->adds]);

        $prodRows = [];
        foreach (array_slice($evArr, 0, 60) as $r) {
            $v = (int) $r->views; $a = (int) $r->adds; $c = (int) $r->checkouts;
            $conv = $v > 0 ? (int) round($c / $v * 100) : 0;
            $prodRows[] = ['cells' => [
                (string) ($names[(int) $r->product_id] ?? ('#' . $r->product_id)),
                $v, $a, $c,
                ['value' => $conv . '%', 'tone' => $conv >= 20 ? 'ok' : ($conv > 0 ? 'warn' : 'default')],
            ]];
        }
        $prodCols = [
            ['label' => 'Product'], ['label' => 'Views', 'align' => 'right'], ['label' => 'Added', 'align' => 'right'],
            ['label' => 'Checkouts', 'align' => 'right'], ['label' => 'View→Buy', 'align' => 'right'],
        ];

        // ---- Top searches (search_events: search | click) ----
        $topSearch = $this->safe(fn () => DB::table('search_events')
            ->where('tenant_id', $t)->where('created_at', '>=', $since)
            ->whereIn('type', ['search', 'click'])
            ->whereRaw("coalesce(trim(query), '') <> ''")
            ->select(DB::raw('lower(trim(query)) as q'), DB::raw('count(*) as times'), DB::raw('max(results) as maxres'))
            ->groupBy(DB::raw('lower(trim(query))'))
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(60)->get());

        $searchRows = [];
        foreach ($topSearch as $r) {
            $has = (int) $r->maxres > 0;
            $searchRows[] = ['cells' => [
                (string) $r->q, (int) $r->times,
                ['value' => $has ? 'yes' : 'no', 'tone' => $has ? 'ok' : 'danger'],
            ]];
        }
        $searchCols = [['label' => 'Search'], ['label' => 'Times', 'align' => 'right'], ['label' => 'Has results']];

        // ---- Search gaps: zero-result searches (the highest-ROI report) ----
        $zero = $this->safe(fn () => DB::table('search_events')
            ->where('tenant_id', $t)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->where('type', 'zero_result')->orWhere('results', 0))
            ->whereRaw("coalesce(trim(query), '') <> ''")
            ->select(DB::raw('lower(trim(query)) as q'), DB::raw('count(*) as times'))
            ->groupBy(DB::raw('lower(trim(query))'))
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(60)->get());

        $zeroRows = [];
        foreach ($zero as $r) {
            $zeroRows[] = ['cells' => [(string) $r->q, ['value' => (int) $r->times, 'tone' => 'danger']]];
        }
        $zeroCols = [['label' => 'Search term (no results)'], ['label' => 'Times', 'align' => 'right']];

        $convPct = $totV > 0 ? (int) round($totC / $totV * 100) : 0;

        // ---- Abandoned carts: added to cart but never checked out ----
        // Cart-level session ids aren't logged, so we use the item-level proxy the funnel already
        // has: adds that never became a checkout, and the products behind them (the actionable list).
        $abandoned = max(0, $totA - $totC);
        $abPct = $totA > 0 ? (int) round($abandoned / $totA * 100) : 0;
        $abRows = [];
        foreach ($evArr as $r) {
            $a = (int) $r->adds; $c = (int) $r->checkouts;
            if ($a > 0 && $c === 0) {
                $abRows[] = ['cells' => [
                    (string) ($names[(int) $r->product_id] ?? ('#' . $r->product_id)),
                    (int) $r->views, $a,
                    ['value' => 'not bought', 'tone' => 'warn'],
                ]];
            }
        }
        usort($abRows, fn ($x, $y) => $y['cells'][2] <=> $x['cells'][2]);
        $abRows = array_slice($abRows, 0, 60);
        $abCols = [
            ['label' => 'Product'], ['label' => 'Views', 'align' => 'right'],
            ['label' => 'Added', 'align' => 'right'], ['label' => 'Outcome'],
        ];

        return (new WorkspacePayload)
            ->object(['type' => 'process:storefront.insights', 'title' => 'Storefront Insights', 'subtitle' => 'Shopper activity · last 30 days'])
            ->financials([
                ['key' => 'views', 'label' => 'Product views', 'value' => $totV, 'tone' => 'default', 'foot' => 'last 30 days'],
                ['key' => 'adds', 'label' => 'Add to cart', 'value' => $totA, 'tone' => $totA > 0 ? 'ok' : 'default', 'foot' => 'items added'],
                ['key' => 'checkouts', 'label' => 'Checkouts', 'value' => $totC, 'tone' => $totC > 0 ? 'ok' : 'default', 'foot' => 'orders placed'],
                ['key' => 'conv', 'label' => 'View → buy', 'value' => $convPct . '%', 'tone' => $convPct >= 10 ? 'ok' : ($convPct > 0 ? 'warn' : 'default'), 'foot' => 'conversion'],
                ['key' => 'abandoned', 'label' => 'Abandoned', 'value' => $abandoned, 'tone' => $abandoned > 0 ? 'warn' : 'default', 'foot' => 'added, not bought (' . $abPct . '%)'],
            ])
            ->tabs([
                ['key' => 'products', 'label' => 'Products', 'count' => count($prodRows)],
                ['key' => 'abandoned', 'label' => 'Abandoned', 'count' => count($abRows)],
                ['key' => 'searches', 'label' => 'Searches', 'count' => count($searchRows)],
                ['key' => 'gaps', 'label' => 'Search gaps', 'count' => count($zeroRows)],
            ])
            ->tabSections([
                'products' => [[
                    'kind' => 'table', 'title' => 'Product performance', 'sub' => 'views → cart → checkout (last 30 days)',
                    'searchable' => true, 'columns' => $prodCols, 'rows' => $prodRows,
                    'empty' => 'No product activity yet — this fills in as shoppers browse the storefront.',
                ]],
                'abandoned' => [[
                    'kind' => 'table', 'title' => 'Added to cart but never bought', 'sub' => 'follow up on WhatsApp, check price, or confirm stock',
                    'searchable' => true, 'columns' => $abCols, 'rows' => $abRows,
                    'empty' => 'No abandoned carts — everything added to a cart was checked out.',
                ]],
                'searches' => [[
                    'kind' => 'table', 'title' => 'Top searches', 'sub' => 'what shoppers are looking for',
                    'searchable' => true, 'columns' => $searchCols, 'rows' => $searchRows,
                    'empty' => 'No searches recorded yet.',
                ]],
                'gaps' => [[
                    'kind' => 'table', 'title' => 'Searches with no results', 'sub' => 'unmet demand — stock these, rename products, or add keywords / synonyms',
                    'searchable' => true, 'columns' => $zeroCols, 'rows' => $zeroRows,
                    'empty' => 'No failed searches — every search found something.',
                ]],
            ])
            ->meta(['capability' => 'storefront.insights', 'listKey' => 'storefront.insights', 'ttl' => 60, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return [];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /** Best-effort query — a missing table or column returns an empty set rather than 500-ing the panel. */
    private function safe(callable $fn)
    {
        try { return $fn(); } catch (\Throwable $e) { return collect(); }
    }
}
