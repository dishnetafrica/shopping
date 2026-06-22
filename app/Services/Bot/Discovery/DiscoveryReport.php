<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — report assembler. Pure logic.
 *
 * Runs every miner/profiler over the corpus + orders and assembles the Business DNA report:
 * the nine sections, per-section confidence, an overall readiness score, and a WhatsApp-ready
 * summary to send the owner. Nothing here activates anything — it only describes findings.
 */
class DiscoveryReport
{
    public static function build(MessageCorpus $corpus, array $orders, string $businessName = '', array $catalogue = [], array $knownAreas = []): array
    {
        $mined    = ProductMiner::analyze($corpus, $orders, $catalogue);
        $products = $mined['products'];
        $faqs     = FaqMiner::mine($corpus);
        $delivery = DeliveryMiner::delivery($corpus, $knownAreas);
        $rules    = DeliveryMiner::rules($corpus);
        $hours    = PatternMiner::hours($corpus);
        $sales    = SalesPatternMiner::mine($corpus);
        $promos   = PatternMiner::promotions($corpus);
        $menus    = PatternMiner::menuPatterns($corpus);
        $langs    = StyleProfiler::languages($corpus);
        $style    = StyleProfiler::ownerStyle($corpus);

        $conf = [
            'products'    => self::avgConf($products),
            'faqs'        => self::avgConf($faqs),
            'delivery'    => (int) ($delivery['confidence'] ?? 0),
            'hours'       => (int) ($hours['confidence'] ?? 0),
            'language'    => $langs ? min(90, 50 + count($langs) * 10) : 0,
            'owner_style' => (int) ($style['confidence'] ?? 0),
            'promotions'  => $promos ? min(80, count($promos) * 20) : 0,
            'menu'        => $menus ? min(80, count($menus) * 20) : 0,
            'rules'       => $rules ? min(85, count($rules) * 25) : 0,
            'sales'       => (int) ($sales['confidence'] ?? 0),
        ];

        $readiness = AutomationReadiness::score($conf);

        return [
            'business_name'   => $businessName,
            'generated_at'    => date('c'),
            'sample'          => ['messages' => $corpus->total(), 'orders' => count($orders)],
            'sections'        => [
                'overview'    => self::overview($businessName, $corpus, $orders, $products),
                'languages'   => $langs,
                'top_products'=> $products,
                'unverified_products' => $mined['unverified'],
                'faqs'        => $faqs,
                'delivery'    => $delivery,
                'hours'       => $hours,
                'promotions'  => $promos,
                'menu'        => $menus,
                'rules'       => $rules,
                'owner_style' => $style,
                'sales_patterns' => $sales,
            ],
            'confidence'      => $conf,
            'readiness_score' => $readiness,
            'readiness_band'  => AutomationReadiness::band($readiness),
        ];
    }

    private static function overview(string $name, MessageCorpus $corpus, array $orders, array $products): array
    {
        $top = array_slice(array_map(fn ($p) => $p['name'], $products), 0, 3);
        return [
            'name'           => $name ?: 'New business',
            'messages_seen'  => $corpus->total(),
            'orders_seen'    => count($orders),
            'owner_messages' => $corpus->ownerCount(),
            'headline'       => $top
                ? 'Looks like a shop selling ' . implode(', ', $top) . ' and more.'
                : 'Not enough order history yet to characterise the catalogue.',
        ];
    }

    private static function avgConf(array $rows): int
    {
        $cs = array_filter(array_map(fn ($r) => (int) ($r['confidence'] ?? 0), $rows));
        if (! $cs) return 0;
        return (int) round(array_sum($cs) / count($cs));
    }

    /** A compact, owner-friendly WhatsApp summary of the report. */
    public static function toWhatsApp(array $report): string
    {
        $s  = $report['sections'];
        $rs = (int) $report['readiness_score'];
        $name = $report['business_name'] ?: 'your business';

        $lines = [];
        $lines[] = "🧬 *Business Discovery — {$name}*";
        $lines[] = "Read {$report['sample']['messages']} messages, {$report['sample']['orders']} orders.";
        $lines[] = "";
        $lines[] = "📊 *Automation readiness: {$rs}%* — {$report['readiness_band']}";
        $lines[] = "";

        $prod = array_slice($s['top_products'], 0, 5);
        if ($prod) {
            $lines[] = "🛒 *Top products:* " . implode(', ', array_map(fn ($p) => $p['name'], $prod));
        }
        if ($s['languages']) {
            $lines[] = "🗣 *Languages:* " . implode(', ', array_map(fn ($l) => "{$l['lang']} {$l['pct']}%", $s['languages']));
        }
        if ($s['faqs']) {
            $lines[] = "❓ *Common questions:* " . implode(', ', array_map(fn ($f) => $f['label'], array_slice($s['faqs'], 0, 5)));
        }
        if (! empty($s['delivery']['fee']) || ! empty($s['delivery']['free_threshold']) || ! empty($s['delivery']['areas'])) {
            $d = $s['delivery'];
            $bits = [];
            if (! empty($d['fee'])) $bits[] = "fee {$d['fee']}";
            if (! empty($d['free_threshold'])) $bits[] = "free over {$d['free_threshold']}";
            if (! empty($d['areas'])) $bits[] = implode('/', $d['areas']);
            $lines[] = "\u{1F69A} *Delivery:* " . implode(', ', $bits);
        }
        if (! empty($s['hours']['text'])) {
            $lines[] = "🕒 *Hours:* {$s['hours']['text']}";
        }
        if ($s['promotions']) {
            $lines[] = "🎁 *Promos seen:* " . implode(', ', array_map(fn ($p) => $p['detail'], array_slice($s['promotions'], 0, 3)));
        }
        if (! empty($s['owner_style']['tone'])) {
            $lines[] = "✍️ *Your style:* {$s['owner_style']['tone']}";
        }

        $lines[] = "";
        $lines[] = "Nothing is live yet. Open your panel → *Discovery* to review and approve what should go active. ✅";

        return implode("\n", $lines);
    }
}
