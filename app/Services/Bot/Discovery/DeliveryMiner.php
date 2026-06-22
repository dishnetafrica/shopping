<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — delivery info + business rules. Pure logic.
 *
 * Reads the owner's own messages (what they tell customers) to recover delivery fee / free-delivery
 * threshold / delivery window / served areas, and order rules (minimum order, payment, prepaid).
 */
class DeliveryMiner
{
    public static function delivery(MessageCorpus $corpus, array $knownAreas = []): array
    {
        $text = $corpus->ownerText();
        $out  = ['free_threshold' => null, 'fee' => null, 'window' => null, 'areas' => [], 'confidence' => 0];
        $hits = 0;

        if (preg_match('/free delivery (?:above|over|from)\s*([0-9][0-9,\.]+)/u', $text, $m)) {
            $out['free_threshold'] = self::num($m[1]); $hits++;
        }
        if (preg_match('/(?:delivery (?:fee|charge|cost)|charge for delivery)\D{0,12}([0-9][0-9,\.]+)/u', $text, $m)) {
            $out['fee'] = self::num($m[1]); $hits++;
        }
        if (preg_match('/delivery (?:in|within|takes)\s*([0-9]{1,3}\s*(?:min|minutes|hour|hours|hrs|hr))/u', $text, $m)) {
            $out['window'] = trim($m[1]); $hits++;
        }
        // "we deliver to X, Y and Z" / "delivery in AREA"
        if (preg_match('/(?:we deliver to|delivery (?:to|in|around))\s+([a-z0-9 ,&]+)/u', $text, $m)) {
            $areas = array_values(array_filter(array_map('trim', preg_split('/[,&]| and /', $m[1]))));
            $areas = array_filter($areas, fn ($a) => self::isPlausibleArea($a, $knownAreas));
            $out['areas'] = array_slice(array_values($areas), 0, 6);
            if ($out['areas']) $hits++;
        }

        $out['confidence'] = min(95, $hits * 28);
        return $out;
    }

    /** A served area must look like a place, not a time/meal/generic word — and, when a zone list
     *  exists, must match one of those known zones. */
    private static function isPlausibleArea(string $a, array $knownAreas): bool
    {
        $a = trim(mb_strtolower($a));
        if (mb_strlen($a) < 3 || mb_strlen($a) > 24) return false;
        if (! preg_match('/^[a-z][a-z\s]+$/', $a)) return false;          // letters only, real word
        $reject = ['time','dinner','lunch','breakfast','morning','evening','afternoon','night',
            'today','tomorrow','now','soon','anytime','area','areas','place','places','order',
            'minutes','hour','hours','day','days','week'];
        foreach (preg_split('/\s+/', $a) as $w) {
            if (in_array($w, $reject, true)) return false;
        }
        if (! empty($knownAreas)) {
            foreach ($knownAreas as $known) {
                $k = trim(mb_strtolower((string) $known));
                if ($k !== '' && (str_contains($a, $k) || str_contains($k, $a))) return true;
            }
            return false;   // zones known but no match → reject
        }
        return true;
    }

    public static function rules(MessageCorpus $corpus): array
    {
        $text  = $corpus->ownerText();
        $rules = [];

        if (preg_match('/(?:minimum order|min order|minimum)\D{0,8}([0-9][0-9,\.]+)/u', $text, $m)) {
            $rules[] = ['rule' => 'minimum_order', 'value' => self::num($m[1]), 'label' => 'Minimum order ' . self::num($m[1])];
        }
        foreach (['mpesa' => 'M-Pesa', 'momo' => 'Mobile Money', 'mobile money' => 'Mobile Money', 'cash' => 'Cash', 'bank transfer' => 'Bank transfer'] as $kw => $label) {
            if (str_contains($text, $kw)) $rules[] = ['rule' => 'payment', 'value' => $label, 'label' => 'Accepts ' . $label];
        }
        if (preg_match('/(prepaid|advance payment|pay (?:first|before)|no cod)/u', $text)) {
            $rules[] = ['rule' => 'prepaid', 'value' => true, 'label' => 'Prepaid / advance required'];
        }
        if (preg_match('/(cash on delivery|\bcod\b|pay on delivery)/u', $text)) {
            $rules[] = ['rule' => 'cod', 'value' => true, 'label' => 'Cash on delivery accepted'];
        }

        // de-dup by (rule,value)
        $seen = []; $u = [];
        foreach ($rules as $r) {
            $key = $r['rule'] . '|' . (is_bool($r['value']) ? (int) $r['value'] : $r['value']);
            if (isset($seen[$key])) continue; $seen[$key] = true; $u[] = $r;
        }
        return $u;
    }

    private static function num(string $s): int { return (int) preg_replace('/[^0-9]/', '', $s); }
}
