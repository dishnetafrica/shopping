<?php

namespace App\Services\Bot\Validation;

/**
 * Platform Validation — discovered-vs-actual comparison. Pure logic, no framework deps.
 *
 * Given what Discovery found and a ground-truth ("actual") for a real business, computes per-
 * category recall/precision and an overall accuracy score, plus readiness predicted-vs-actual.
 * This is how we measure onboarding quality across real shops.
 */
class ValidationComparator
{
    /** Per-category weights for the overall accuracy score (sum = 1.0). */
    private const WEIGHTS = [
        'products'  => 0.30,
        'faqs'      => 0.25,
        'delivery'  => 0.15,
        'languages' => 0.10,
        'offers'    => 0.10,
        'readiness' => 0.10,
    ];

    /**
     * @param array $sections           Discovery report sections (DiscoveryReport::build → 'sections')
     * @param array $actual             ['products'=>[],'faqs'=>[](topics),'delivery_areas'=>[],
     *                                   'languages'=>[],'offers'=>int,'readiness'=>int]
     * @param int   $predictedReadiness readiness % from the Go-Live evaluation
     */
    public static function compare(array $sections, array $actual, int $predictedReadiness): array
    {
        $dProducts = array_map(fn ($p) => (string) ($p['name'] ?? ''), $sections['top_products'] ?? []);
        $dFaqs     = array_map(fn ($f) => (string) ($f['topic'] ?? ''), $sections['faqs'] ?? []);
        $dAreas    = (array) ($sections['delivery']['areas'] ?? []);
        $dLangs    = array_map(fn ($l) => (string) ($l['lang'] ?? ''), $sections['languages'] ?? []);
        $dOffers   = count($sections['promotions'] ?? []);

        $products = self::setMetrics($dProducts, (array) ($actual['products'] ?? []), true);
        $faqs     = self::setMetrics($dFaqs, (array) ($actual['faqs'] ?? []), false);
        $delivery = self::setMetrics($dAreas, (array) ($actual['delivery_areas'] ?? []), false);
        $langs    = self::setMetrics($dLangs, (array) ($actual['languages'] ?? []), false);

        $offers   = self::countAccuracy($dOffers, (int) ($actual['offers'] ?? 0));
        $aReady   = (int) ($actual['readiness'] ?? $predictedReadiness);
        $readiness = max(0, 100 - abs($predictedReadiness - $aReady));

        $overall = 0.0;
        $overall += $products['recall']  * self::WEIGHTS['products'];
        $overall += $faqs['recall']      * self::WEIGHTS['faqs'];
        $overall += $delivery['recall']  * self::WEIGHTS['delivery'];
        $overall += $langs['recall']     * self::WEIGHTS['languages'];
        $overall += $offers              * self::WEIGHTS['offers'];
        $overall += $readiness           * self::WEIGHTS['readiness'];

        return [
            'products'  => $products,
            'faqs'      => $faqs,
            'delivery'  => $delivery,
            'languages' => $langs,
            'offers'    => ['detected' => $dOffers, 'actual' => (int) ($actual['offers'] ?? 0), 'accuracy' => $offers],
            'readiness' => ['predicted' => $predictedReadiness, 'actual' => $aReady, 'accuracy' => $readiness],
            'overall_accuracy' => (int) round($overall),
        ];
    }

    /**
     * Recall/precision of a detected set against an actual set.
     * @param bool $fuzzy use token-subset matching (for product names); else normalized equality
     */
    private static function setMetrics(array $detected, array $actual, bool $fuzzy): array
    {
        $D = self::norm($detected);
        $A = self::norm($actual);

        $foundActual = 0;
        foreach ($A as $a) {
            foreach ($D as $d) {
                if (self::match($d, $a, $fuzzy)) { $foundActual++; break; }
            }
        }
        $matchedDetected = 0;
        foreach ($D as $d) {
            foreach ($A as $a) {
                if (self::match($d, $a, $fuzzy)) { $matchedDetected++; break; }
            }
        }

        $recall    = $A ? (int) round($foundActual / count($A) * 100) : ($D ? 0 : 100);
        $precision = $D ? (int) round($matchedDetected / count($D) * 100) : 100;

        return [
            'detected'  => count($D),
            'actual'    => count($A),
            'matched'   => $foundActual,
            'recall'    => $recall,
            'precision' => $precision,
        ];
    }

    private static function match(string $d, string $a, bool $fuzzy): bool
    {
        if ($d === $a) return true;
        if (! $fuzzy) return false;
        $dt = array_filter(explode(' ', $d));
        $at = array_filter(explode(' ', $a));
        if (! $dt || ! $at) return false;
        // token-subset either direction (e.g. "dosa" ⊂ "masala dosa")
        $short = count($dt) <= count($at) ? $dt : $at;
        $long  = count($dt) <= count($at) ? $at : $dt;
        foreach ($short as $tok) if (! in_array($tok, $long, true)) return false;
        return true;
    }

    private static function countAccuracy(int $detected, int $actual): int
    {
        if ($actual <= 0) return $detected === 0 ? 100 : 60;
        return (int) round(max(0, 100 - abs($detected - $actual) / $actual * 100));
    }

    private static function norm(array $list): array
    {
        $out = [];
        foreach ($list as $x) {
            $s = mb_strtolower(trim((string) $x));
            $s = preg_replace('/\b\d+\s*(kg|g|gram|grams|ml|l|ltr|litre|pcs|pc|pack|x)\b/u', '', $s);
            $s = trim(preg_replace('/[^a-z\s]/', ' ', (string) $s));
            $s = trim(preg_replace('/\s+/', ' ', $s));
            if ($s !== '') $out[$s] = $s;
        }
        return array_values($out);
    }
}
