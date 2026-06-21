<?php

namespace App\Services\Bot\Readiness;

/**
 * Business Readiness — formal go-live assessment. Pure logic, no framework deps.
 *
 * Takes a Business Discovery report plus the current owner-approval state and produces seven
 * category scores, an overall score, a classification + recommended operating mode, and the three
 * recommendation lists (what's missing, what needs approval, what needs confirmation).
 *
 * Invariants:
 *   - Coverage categories measure data completeness; approval is its own category.
 *   - Autonomous is gated: unless owner approval is high, the recommended mode is capped at
 *     AI Assisted. The owner must approve before a business is told it can run autonomously.
 *   - This evaluator never changes any setting; it only describes readiness.
 */
class BusinessReadinessEvaluator
{
    /** Category weights for the overall score (sum = 1.0). Approval is weighted heaviest. */
    private const WEIGHTS = [
        'products'       => 0.18,
        'faqs'           => 0.15,
        'delivery'       => 0.13,
        'offers'         => 0.10,
        'language'       => 0.10,
        'confidence'     => 0.12,
        'owner_approval' => 0.22,
    ];

    private const DEFAULT_SUPPORTED = ['English', 'Gujlish', 'Swahili', 'Hindi'];
    private const APPROVAL_GATE = 75;   // below this, never recommend Autonomous

    /**
     * @param array $report Business Discovery report (from DiscoveryReport::build)
     * @param array $state  ['approved'=>[section=>bool], 'areas_seen'=>string[],
     *                       'supported_languages'=>string[], 'faqs_approved'=>int,
     *                       'hours_confirmed'=>bool]
     */
    public static function evaluate(int $tenantId, array $report, array $state = []): array
    {
        $s = $report['sections'] ?? [];

        $scores = [
            'products'       => self::productCoverage($s['top_products'] ?? []),
            'faqs'           => self::faqCoverage($s['faqs'] ?? []),
            'delivery'       => self::deliveryCoverage($s['delivery'] ?? [], $state),
            'offers'         => self::offerCoverage($s['promotions'] ?? [], $s['menu'] ?? []),
            'language'       => self::languageCoverage($s['languages'] ?? [], $state),
            'confidence'     => self::confidenceQuality($report['confidence'] ?? []),
            'owner_approval' => self::approvalStatus($state),
        ];

        $overall = 0.0;
        foreach (self::WEIGHTS as $k => $w) $overall += ($scores[$k] ?? 0) * $w;
        $overall = (int) round($overall);

        $classification = ReadinessModes::classify($overall);
        // gate: cannot recommend Autonomous until owner approval is high enough
        if ($classification === ReadinessModes::AUTONOMOUS && $scores['owner_approval'] < self::APPROVAL_GATE) {
            $classification = ReadinessModes::ASSISTED;
        }
        $mode = ReadinessModes::modeFor($classification);

        return [
            'tenant_id'       => $tenantId,
            'category_scores' => $scores,
            'overall_score'   => $overall,
            'classification'  => $classification,
            'recommended_mode'=> $mode,
            'recommendations' => self::recommendations($report, $state, $scores),
            'generated_at'    => date('c'),
        ];
    }

    // ---------------------------------------------------------------- categories

    private static function productCoverage(array $products): int
    {
        $count   = count($products);
        $avgConf = self::avgConf($products);
        $countScore = min(100, $count * 10);                 // 10+ products → full breadth
        return (int) round(0.4 * $countScore + 0.6 * $avgConf);
    }

    private static function faqCoverage(array $faqs): int
    {
        $count   = count($faqs);
        $avgConf = self::avgConf($faqs);
        $breadth = min(100, $count * 20);                    // 5+ topics → full breadth
        return (int) round(0.5 * $breadth + 0.5 * $avgConf);
    }

    private static function deliveryCoverage(array $delivery, array $state): int
    {
        $seen    = self::normList($state['areas_seen'] ?? []);
        $covered = self::normList($delivery['areas'] ?? []);
        $hasFee  = ! empty($delivery['fee']) || ! empty($delivery['free_threshold']);

        if (! $seen) {
            // no customer-area signal — fall back to how confidently delivery was discovered
            return (int) ($delivery['confidence'] ?? 0);
        }
        $hit = count(array_intersect($seen, $covered));
        $areaScore = $hit / max(1, count($seen)) * 100;
        return (int) round(0.7 * $areaScore + 0.3 * ($hasFee ? 100 : 0));
    }

    private static function offerCoverage(array $promos, array $menus): int
    {
        return (int) min(100, count($promos) * 30 + count($menus) * 20);
    }

    private static function languageCoverage(array $languages, array $state): int
    {
        $supported = array_map('mb_strtolower', $state['supported_languages'] ?? self::DEFAULT_SUPPORTED);
        if (! $languages) return 0;
        $covered = 0;
        foreach ($languages as $l) {
            if (in_array(mb_strtolower((string) ($l['lang'] ?? '')), $supported, true)) {
                $covered += (int) ($l['pct'] ?? 0);
            }
        }
        return (int) min(100, $covered);
    }

    private static function confidenceQuality(array $confMap): int
    {
        $vals = array_filter(array_map('intval', array_values($confMap)), fn ($v) => $v > 0);
        if (! $vals) return 0;
        return (int) round(array_sum($vals) / count($vals));
    }

    private static function approvalStatus(array $state): int
    {
        $sections = ['products', 'faqs', 'delivery', 'hours', 'offers', 'language'];
        $approved = (array) ($state['approved'] ?? []);
        $yes = 0;
        foreach ($sections as $sec) if (! empty($approved[$sec])) $yes++;
        return (int) round($yes / count($sections) * 100);
    }

    // ------------------------------------------------------------ recommendations

    private static function recommendations(array $report, array $state, array $scores): array
    {
        $s = $report['sections'] ?? [];

        // Missing delivery rules: areas customers come from that aren't covered
        $seen    = self::normList($state['areas_seen'] ?? []);
        $covered = self::normList($s['delivery']['areas'] ?? []);
        $missingAreas = array_values(array_diff($seen, $covered));
        $missing = [];
        if ($missingAreas) {
            $missing[] = ['item' => 'Delivery rules', 'detail' => 'No delivery rule for: ' . self::titleList($missingAreas), 'targets' => array_map('ucfirst', $missingAreas)];
        }
        if (empty($s['delivery']['fee']) && empty($s['delivery']['free_threshold'])) {
            $missing[] = ['item' => 'Delivery fee', 'detail' => 'No delivery fee or free-delivery threshold found'];
        }

        // Need approval: discovered-but-unapproved sections that hold data
        $approved = (array) ($state['approved'] ?? []);
        $needApproval = [];
        if (empty($approved['faqs']) && ($n = count($s['faqs'] ?? [])) > 0) {
            $needApproval[] = ['item' => 'FAQs', 'detail' => "{$n} discovered FAQ" . ($n === 1 ? '' : 's'), 'count' => $n];
        }
        if (empty($approved['products']) && ($n = count($s['top_products'] ?? [])) > 0) {
            $needApproval[] = ['item' => 'Products', 'detail' => "{$n} discovered product" . ($n === 1 ? '' : 's'), 'count' => $n];
        }
        if (empty($approved['offers']) && ($n = count($s['promotions'] ?? [])) > 0) {
            $needApproval[] = ['item' => 'Offers', 'detail' => "{$n} discovered offer" . ($n === 1 ? '' : 's'), 'count' => $n];
        }

        // Need confirmation: present-but-unconfirmed facts
        $needConfirm = [];
        if (! empty($s['hours']['text']) && empty($state['hours_confirmed'])) {
            $needConfirm[] = ['item' => 'Opening hours', 'detail' => 'Confirm: ' . $s['hours']['text']];
        }
        if (! empty($s['delivery']['fee']) && empty($approved['delivery'])) {
            $needConfirm[] = ['item' => 'Delivery fee', 'detail' => 'Confirm delivery fee ' . $s['delivery']['fee']];
        }
        if (($scores['language'] ?? 0) < 80 && ! empty($s['languages'])) {
            $needConfirm[] = ['item' => 'Languages', 'detail' => 'Some customer languages may be unsupported'];
        }

        return ['missing' => $missing, 'need_approval' => $needApproval, 'need_confirmation' => $needConfirm];
    }

    // ------------------------------------------------------------------- helpers

    private static function avgConf(array $rows): int
    {
        $cs = array_filter(array_map(fn ($r) => (int) ($r['confidence'] ?? 0), $rows));
        return $cs ? (int) round(array_sum($cs) / count($cs)) : 0;
    }

    private static function normList(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($x) => mb_strtolower(trim((string) $x)), $list))));
    }

    private static function titleList(array $list): string
    {
        return implode(', ', array_map('ucfirst', $list));
    }

    /** Owner-facing WhatsApp Go-Live summary. */
    public static function toWhatsApp(array $report, string $businessName = ''): string
    {
        $cs = $report['category_scores'];
        $name = $businessName !== '' ? $businessName : 'your business';

        $lines = [];
        $lines[] = "🚦 *Go-Live Report — {$name}*";
        $lines[] = "";
        $lines[] = "*Overall readiness: {$report['overall_score']}%* → {$report['classification']}";
        $lines[] = "Recommended: *{$report['recommended_mode']}* (you approve before it goes live)";
        $lines[] = "";
        $lines[] = "Products {$cs['products']}%  •  FAQs {$cs['faqs']}%  •  Delivery {$cs['delivery']}%";
        $lines[] = "Offers {$cs['offers']}%  •  Language {$cs['language']}%  •  Approval {$cs['owner_approval']}%";

        $r = $report['recommendations'];
        if ($r['missing']) {
            $lines[] = "";
            $lines[] = "⚠️ *Missing:*";
            foreach ($r['missing'] as $m) $lines[] = "• {$m['detail']}";
        }
        if ($r['need_approval']) {
            $lines[] = "";
            $lines[] = "✅ *Needs approval:*";
            foreach ($r['need_approval'] as $m) $lines[] = "• {$m['detail']}";
        }
        if ($r['need_confirmation']) {
            $lines[] = "";
            $lines[] = "❓ *Confirm:*";
            foreach ($r['need_confirmation'] as $m) $lines[] = "• {$m['detail']}";
        }

        $lines[] = "";
        $lines[] = "AI stays off until you pick a mode in your panel. Nothing switches on by itself.";
        return implode("\n", $lines);
    }
}
