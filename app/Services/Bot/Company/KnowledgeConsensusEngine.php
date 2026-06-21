<?php

namespace App\Services\Bot\Company;

/**
 * Multi-Employee Learning — knowledge consensus. Pure logic, no framework deps.
 *
 * Takes per-employee Discovery reports (each built by the existing DiscoveryReport) and builds a
 * consensus so the AI represents the BUSINESS, not one salesperson:
 *
 *   Company Memory  — facts agreed across employees: pricing/delivery, products, FAQs, offers.
 *   Employee Memory — individual habits: communication style, emoji use, greeting, upsell.
 *   Conflicts       — the same company-level fact reported differently by different employees.
 *
 * Threshold: a fact is "company" when ≥ min(2, employee_count) employees report it — so a single-
 * employee business still yields a Company DNA (that employee is the company), while multi-employee
 * businesses require corroboration before a fact becomes company-wide.
 */
class KnowledgeConsensusEngine
{
    /**
     * @param array $employees [ ['employee'=>string, 'report'=>discoveryReport], ... ]
     */
    public static function consensus(array $employees): array
    {
        $names = array_values(array_map(fn ($e) => (string) ($e['employee'] ?? ''), $employees));
        $total = count($employees);
        if ($total === 0) return self::empty();
        $threshold = min(2, $total);

        // --- collect company-level facts: value => [employees] ---
        $products = $faqs = $offers = $areas = [];
        $feeVotes = $thrVotes = [];
        $employeeMem = [];

        foreach ($employees as $e) {
            $emp = (string) ($e['employee'] ?? '');
            $sec = $e['report']['sections'] ?? [];
            $employeeMem[$emp] = ['unique_products' => [], 'unique_faqs' => [], 'unique_offers' => [], 'style' => self::styleOf($sec), 'upsell' => self::upsellOf($sec)];

            foreach ($sec['top_products'] ?? [] as $p) self::vote($products, (string) ($p['name'] ?? ''), $emp);
            foreach ($sec['faqs'] ?? [] as $f)        self::vote($faqs, (string) ($f['topic'] ?? ''), $emp);
            foreach ($sec['promotions'] ?? [] as $o)  self::vote($offers, (string) ($o['detail'] ?? ''), $emp);
            foreach ($sec['delivery']['areas'] ?? [] as $a) self::vote($areas, (string) $a, $emp);

            $fee = $sec['delivery']['fee'] ?? null;
            if (! empty($fee)) self::vote($feeVotes, (string) $fee, $emp);
            $thr = $sec['delivery']['free_threshold'] ?? null;
            if (! empty($thr)) self::vote($thrVotes, (string) $thr, $emp);
        }

        // --- partition set-categories into company vs employee-unique ---
        $companyProducts = self::partition($products, $threshold, $total, $employeeMem, 'unique_products');
        $companyFaqs     = self::partition($faqs, $threshold, $total, $employeeMem, 'unique_faqs');
        $companyOffers   = self::partition($offers, $threshold, $total, $employeeMem, 'unique_offers');
        $companyAreas    = self::partition($areas, $threshold, $total, $employeeMem, null);

        // --- scalar delivery facts (company-level by nature) + conflict detection ---
        $conflicts = [];
        $fee = self::scalarConsensus($feeVotes, $total, 'delivery_fee', $conflicts);
        $thr = self::scalarConsensus($thrVotes, $total, 'free_delivery_threshold', $conflicts);

        $companyMemory = [
            'products' => $companyProducts,
            'faqs'     => $companyFaqs,
            'offers'   => $companyOffers,
            'delivery' => ['fee' => $fee, 'free_threshold' => $thr, 'areas' => $companyAreas],
        ];

        $confidence = [
            'products' => self::catConfidence($companyProducts),
            'faqs'     => self::catConfidence($companyFaqs),
            'offers'   => self::catConfidence($companyOffers),
            'delivery' => self::catConfidence(array_filter([$fee, $thr])) ?: self::catConfidence($companyAreas),
        ];
        $confidence['overall'] = (int) round(array_sum($confidence) / max(1, count($confidence)));

        return [
            'employees'       => $names,
            'employee_count'  => $total,
            'company_memory'  => $companyMemory,
            'employee_memory' => $employeeMem,
            'conflicts'       => $conflicts,
            'confidence'      => $confidence,
            'report'          => self::report($companyMemory, $employeeMem, $conflicts, $confidence, $names),
        ];
    }

    // ----------------------------------------------------------------- collectors

    private static function vote(array &$bucket, string $value, string $emp): void
    {
        $v = trim($value);
        if ($v === '') return;
        $key = mb_strtolower($v);
        if (! isset($bucket[$key])) $bucket[$key] = ['display' => $v, 'employees' => []];
        if (! in_array($emp, $bucket[$key]['employees'], true)) $bucket[$key]['employees'][] = $emp;
    }

    /** Split a voted bucket into company facts (≥threshold) vs employee-unique (the rest). */
    private static function partition(array $bucket, int $threshold, int $total, array &$employeeMem, ?string $uniqueKey): array
    {
        $company = [];
        foreach ($bucket as $row) {
            $count = count($row['employees']);
            if ($count >= $threshold) {
                $company[] = [
                    'value'     => $row['display'],
                    'employees' => $row['employees'],
                    'agreement' => (int) round($count / max(1, $total) * 100),
                    'confidence'=> min(99, 50 + $count * 15),
                ];
            } elseif ($uniqueKey !== null) {
                foreach ($row['employees'] as $emp) {
                    $employeeMem[$emp][$uniqueKey][] = $row['display'];
                }
            }
        }
        // strongest agreement first
        usort($company, fn ($a, $b) => $b['agreement'] <=> $a['agreement']);
        return $company;
    }

    /** A single company-level value with majority vote; records a conflict on disagreement. */
    private static function scalarConsensus(array $votes, int $total, string $key, array &$conflicts): ?array
    {
        if (! $votes) return null;
        // majority
        $best = null;
        foreach ($votes as $row) {
            if ($best === null || count($row['employees']) > count($best['employees'])) $best = $row;
        }
        if (count($votes) > 1) {
            $conflicts[] = [
                'category' => 'delivery',
                'key'      => $key,
                'values'   => array_map(fn ($r) => ['value' => $r['display'], 'employees' => $r['employees']], array_values($votes)),
                'resolved' => $best['display'],
            ];
        }
        $count = count($best['employees']);
        return [
            'value'      => $best['display'],
            'employees'  => $best['employees'],
            'agreement'  => (int) round($count / max(1, $total) * 100),
            'confidence' => count($votes) > 1 ? 55 : min(99, 50 + $count * 15),
            'contested'  => count($votes) > 1,
        ];
    }

    // ------------------------------------------------------------ employee profiles

    private static function styleOf(array $sec): array
    {
        $s = $sec['owner_style'] ?? [];
        return [
            'tone'          => (string) ($s['tone'] ?? 'unknown'),
            'emoji_per_msg' => (float) ($s['emoji_per_msg'] ?? 0),
            'greeting_rate' => (int) ($s['greeting_rate'] ?? 0),
        ];
    }

    private static function upsellOf(array $sec): array
    {
        $n = count($sec['promotions'] ?? []);
        $level = $n >= 2 ? 'high' : ($n === 1 ? 'some' : 'none');
        return ['level' => $level, 'offers_pushed' => $n];
    }

    // ------------------------------------------------------------------ confidence

    private static function catConfidence(array $items): int
    {
        $cs = array_filter(array_map(fn ($i) => (int) ($i['confidence'] ?? 0), $items));
        return $cs ? (int) round(array_sum($cs) / count($cs)) : 0;
    }

    // ------------------------------------------------------------- Company DNA report

    private static function report(array $company, array $employeeMem, array $conflicts, array $confidence, array $names): array
    {
        $rules = [];
        if ($company['products']) $rules[] = 'Sells: ' . implode(', ', array_map(fn ($p) => $p['value'], array_slice($company['products'], 0, 8)));
        if ($company['faqs'])     $rules[] = 'Answers: ' . implode(', ', array_map(fn ($f) => $f['value'], $company['faqs']));
        if (! empty($company['delivery']['fee']))            $rules[] = 'Delivery fee: ' . $company['delivery']['fee']['value'];
        if (! empty($company['delivery']['free_threshold'])) $rules[] = 'Free delivery above: ' . $company['delivery']['free_threshold']['value'];
        if ($company['delivery']['areas']) $rules[] = 'Delivers to: ' . implode(', ', array_map(fn ($a) => $a['value'], $company['delivery']['areas']));
        if ($company['offers'])   $rules[] = 'Offers: ' . implode(', ', array_map(fn ($o) => $o['value'], $company['offers']));

        $variations = [];
        foreach ($employeeMem as $emp => $m) {
            $bits = [];
            if ($m['unique_products']) $bits[] = 'mentions ' . implode('/', $m['unique_products']);
            if ($m['unique_offers'])   $bits[] = 'pushes ' . implode('/', $m['unique_offers']);
            $bits[] = 'style: ' . $m['style']['tone'];
            $bits[] = 'upsell: ' . $m['upsell']['level'];
            $variations[] = ['employee' => $emp, 'notes' => implode('; ', $bits)];
        }

        return [
            'common_company_rules'    => $rules,
            'employee_variations'     => $variations,
            'conflicting_information' => array_map(fn ($c) => [
                'fact'     => $c['key'],
                'values'   => array_map(fn ($v) => $v['value'] . ' (' . implode(',', $v['employees']) . ')', $c['values']),
                'resolved' => $c['resolved'],
            ], $conflicts),
            'confidence_levels'       => $confidence,
        ];
    }

    private static function empty(): array
    {
        return [
            'employees' => [], 'employee_count' => 0,
            'company_memory' => ['products' => [], 'faqs' => [], 'offers' => [], 'delivery' => ['fee' => null, 'free_threshold' => null, 'areas' => []]],
            'employee_memory' => [], 'conflicts' => [],
            'confidence' => ['products' => 0, 'faqs' => 0, 'offers' => 0, 'delivery' => 0, 'overall' => 0],
            'report' => ['common_company_rules' => [], 'employee_variations' => [], 'conflicting_information' => [], 'confidence_levels' => []],
        ];
    }
}
