<?php

namespace App\Services\Analytics;

use App\Models\Product;
use App\Services\Bot\ComboAnalytics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Practical merchant analytics — answers: what sells, what converts, which combo works,
 * where customers drop off, which products need better photos. Tenant-scoped, cached 5 min.
 */
class DashboardAnalytics
{
    private const CONFIRMED = ['Confirmed', 'Completed', 'Delivered'];

    public function payload(int $tenantId, bool $fresh = false): array
    {
        if ($fresh) Cache::forget("dash:payload:{$tenantId}");

        return Cache::remember("dash:payload:{$tenantId}", now()->addMinutes(5), function () use ($tenantId) {
            return [
                'cards'          => $this->cards($tenantId),
                'orders_by_day'  => $this->byDay($tenantId, 'count', 30),
                'revenue_by_day' => $this->byDay($tenantId, 'revenue', 30),
                'top_products'   => $this->topProducts($tenantId, 8),
                'top_categories' => $this->topCategories($tenantId, 8),
                'most_viewed'    => $this->mostViewed($tenantId, 15),
                'most_ordered'   => $this->mostOrdered($tenantId, 15),
                'combos'         => app(ComboAnalytics::class)->summary($tenantId, 15),
                'gallery_usage'  => $this->galleryUsage($tenantId, 15),
                'funnel'         => $this->funnel($tenantId),
                'fresh'          => $this->freshPerformance($tenantId, 20),
                'generated_at'   => now()->format('H:i'),
            ];
        });
    }

    /** Headline cards. */
    public function cards(int $tid): array
    {
        $o = fn () => DB::table('orders')->where('tenant_id', $tid);

        $created30 = (int) $o()->where('created_at', '>=', now()->subDays(30))->count();
        $confirmed30 = (int) $o()->where('created_at', '>=', now()->subDays(30))->whereIn('status', self::CONFIRMED)->count();
        $placed30 = (int) $o()->where('created_at', '>=', now()->subDays(30))->where('status', '<>', 'Cancelled')->count();
        $revenue30 = (float) $o()->where('created_at', '>=', now()->subDays(30))->where('status', '<>', 'Cancelled')->sum('total');

        $imp = (int) DB::table('combo_impressions')->where('tenant_id', $tid)->count();
        $conv = (int) DB::table('combo_conversions')->where('tenant_id', $tid)->count();

        return [
            'orders_today'     => (int) $o()->where('created_at', '>=', now()->startOfDay())->count(),
            'orders_7d'        => (int) $o()->where('created_at', '>=', now()->subDays(7))->count(),
            'orders_30d'       => $created30,
            'orders_confirmed' => $confirmed30,
            'checkout_conv'    => $created30 > 0 ? round($confirmed30 * 100 / $created30, 1) : 0.0,
            'aov'              => $placed30 > 0 ? round($revenue30 / $placed30) : 0,
            'revenue'          => round($revenue30),
            'combo_conv'       => $imp > 0 ? round($conv * 100 / $imp, 1) : 0.0,
        ];
    }

    /** Daily series (last N days, zero-filled) for orders or revenue. */
    public function byDay(int $tid, string $metric, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $q = DB::table('orders')->where('tenant_id', $tid)->where('created_at', '>=', $start);
        if ($metric === 'revenue') $q->where('status', '<>', 'Cancelled');

        $sel = $metric === 'revenue' ? 'COALESCE(SUM(total),0)' : 'COUNT(*)';
        $raw = $q->selectRaw("to_char(created_at::date,'YYYY-MM-DD') as d, {$sel} as v")
            ->groupBy('d')->pluck('v', 'd')->toArray();

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $out[] = ['label' => substr($day, 5), 'value' => (float) ($raw[$day] ?? 0)];
        }
        return $out;
    }

    public function topProducts(int $tid, int $limit): array
    {
        $rows = DB::table('order_items')->where('tenant_id', $tid)->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(qty) as qty')->groupBy('product_id')
            ->orderByDesc('qty')->limit($limit)->get();
        $names = $this->names($rows->pluck('product_id')->all());
        return $rows->map(fn ($r) => ['label' => (string) ($names[(int) $r->product_id] ?? ('#' . $r->product_id)), 'value' => (float) $r->qty])->all();
    }

    public function topCategories(int $tid, int $limit): array
    {
        $rows = DB::table('order_items as oi')->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('oi.tenant_id', $tid)->whereNotNull('p.category')->where('p.category', '<>', '')
            ->selectRaw('p.category as cat, SUM(oi.qty) as qty')->groupBy('p.category')
            ->orderByDesc('qty')->limit($limit)->get();
        return $rows->map(fn ($r) => ['label' => (string) $r->cat, 'value' => (float) $r->qty])->all();
    }

    /** Which products are viewed a lot but rarely ordered → need better photos/price. */
    public function mostViewed(int $tid, int $limit): array
    {
        $views = DB::table('product_events')->where('tenant_id', $tid)->where('event', 'view')->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as v')->groupBy('product_id')->pluck('v', 'product_id')->toArray();
        if (! $views) return [];
        $orders = DB::table('order_items')->where('tenant_id', $tid)->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(DISTINCT order_id) as o')->groupBy('product_id')->pluck('o', 'product_id')->toArray();

        arsort($views);
        $ids   = array_slice(array_keys($views), 0, $limit);
        $names = $this->names($ids);
        $out = [];
        foreach ($ids as $pid) {
            $v = (int) $views[$pid]; $o = (int) ($orders[$pid] ?? 0);
            $out[] = ['product' => (string) ($names[(int) $pid] ?? ('#' . $pid)), 'views' => $v, 'orders' => $o,
                      'conv_pct' => $v > 0 ? round($o * 100 / $v, 1) : 0.0];
        }
        return $out;
    }

    public function mostOrdered(int $tid, int $limit): array
    {
        $rows = DB::table('order_items')->where('tenant_id', $tid)->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(qty) as qty, SUM(price * qty) as revenue')
            ->groupBy('product_id')->orderByDesc('qty')->limit($limit)->get();
        $names = $this->names($rows->pluck('product_id')->all());
        return $rows->map(fn ($r) => ['product' => (string) ($names[(int) $r->product_id] ?? ('#' . $r->product_id)),
            'qty' => (float) $r->qty, 'revenue' => round((float) $r->revenue)])->all();
    }

    public function galleryUsage(int $tid, int $limit): array
    {
        $rows = DB::table('product_events')->where('tenant_id', $tid)->where('event', 'gallery')->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as reqs')->groupBy('product_id')->orderByDesc('reqs')->limit($limit)->get();
        $names = $this->names($rows->pluck('product_id')->all());
        return $rows->map(fn ($r) => ['product' => (string) ($names[(int) $r->product_id] ?? ('#' . $r->product_id)),
            'opens' => (int) $r->reqs, 'more_photos' => (int) $r->reqs])->all();
    }

    /** Funnel counts over the last 30 days. */
    public function funnel(int $tid): array
    {
        $ev = fn ($e) => (int) DB::table('product_events')->where('tenant_id', $tid)->where('event', $e)
            ->where('created_at', '>=', now()->subDays(30))->count();
        $confirmed = (int) DB::table('orders')->where('tenant_id', $tid)
            ->where('created_at', '>=', now()->subDays(30))->whereIn('status', self::CONFIRMED)->count();

        return [
            'viewed'           => $ev('view'),
            'added'            => $ev('add'),
            'checkout_started' => $ev('checkout'),
            'confirmed'        => $confirmed,
        ];
    }

    public function freshPerformance(int $tid, int $limit): array
    {
        $fresh = Product::where('is_fresh_today', true)->limit($limit)->get(['id', 'name']);
        if ($fresh->isEmpty()) return [];

        $stats = DB::table('order_items')->where('tenant_id', $tid)->whereIn('product_id', $fresh->pluck('id')->all())
            ->selectRaw('product_id, SUM(qty) as qty, SUM(price * qty) as revenue')->groupBy('product_id')->get()->keyBy('product_id');

        return $fresh->map(function ($p) use ($stats) {
            $s = $stats->get($p->id);
            return ['product' => (string) $p->name, 'fresh' => true,
                'orders' => (float) ($s->qty ?? 0), 'revenue' => round((float) ($s->revenue ?? 0))];
        })->all();
    }

    /** Rows for a single CSV export (key matches a payload section). */
    public function csvRows(int $tid, string $report): array
    {
        switch ($report) {
            case 'most_ordered':   return [['Product', 'Quantity Sold', 'Revenue'], ...array_map(fn ($r) => [$r['product'], $r['qty'], $r['revenue']], $this->mostOrdered($tid, 200))];
            case 'most_viewed':    return [['Product', 'Views', 'Orders', 'Conversion %'], ...array_map(fn ($r) => [$r['product'], $r['views'], $r['orders'], $r['conv_pct']], $this->mostViewed($tid, 200))];
            case 'combos':         return [['Source', 'Recommended', 'Impressions', 'Conversions', 'Conversion %'], ...array_map(fn ($r) => [$r['source'], $r['recommended'], $r['shown'], $r['accepted'], $r['conv_pct']], app(ComboAnalytics::class)->summary($tid, 200))];
            case 'gallery_usage':  return [['Product', 'Gallery Opens', 'More Photos Requests'], ...array_map(fn ($r) => [$r['product'], $r['opens'], $r['more_photos']], $this->galleryUsage($tid, 200))];
            case 'top_products':   return [['Product', 'Quantity'], ...array_map(fn ($r) => [$r['label'], $r['value']], $this->topProducts($tid, 200))];
            case 'top_categories': return [['Category', 'Quantity'], ...array_map(fn ($r) => [$r['label'], $r['value']], $this->topCategories($tid, 200))];
            case 'fresh':          return [['Product', 'Fresh Today', 'Orders', 'Revenue'], ...array_map(fn ($r) => [$r['product'], $r['fresh'] ? 'yes' : 'no', $r['orders'], $r['revenue']], $this->freshPerformance($tid, 200))];
            default:               return [['Metric', 'Value'], ...array_map(fn ($k, $v) => [$k, $v], array_keys($this->cards($tid)), array_values($this->cards($tid)))];
        }
    }

    private function names(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($x) => $x > 0));
        if (! $ids) return [];
        return Product::whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->toArray();
    }
}
