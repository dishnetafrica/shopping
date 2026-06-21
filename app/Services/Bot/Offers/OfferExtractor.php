<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — offer extractor. Pure logic, no framework deps.
 *
 * Two entry points produce the SAME canonical offer array:
 *   - fromVision($json): normalise the structured JSON returned by the vision model.
 *   - fromText($ocr):    deterministic best-effort parse of raw OCR / caption text.
 *
 * Canonical shape:
 *   ['found'=>bool,'title'=>?string,'price'=>?int,'currency'=>?string,'description'=>?string,
 *    'items'=>string[],'day'=>?string,'type'=>string,'confidence'=>int]
 */
class OfferExtractor
{
    public static function fromVision(array $j): array
    {
        $found = ! array_key_exists('found', $j) || $j['found'] !== false;
        $title = self::cleanTitle((string) ($j['title'] ?? ''));
        $items = self::normItems($j['items'] ?? []);
        $price = self::priceFrom($j['price'] ?? null) ?? self::priceFrom((string) ($j['price_text'] ?? ''));
        $cur   = self::currencyFrom((string) ($j['currency'] ?? '')) ?? self::currencyFrom((string) ($j['price_text'] ?? ''));
        $day   = self::dayFrom((string) ($j['day'] ?? ''));
        $typeHint = (string) ($j['offer_type'] ?? $j['type'] ?? '');
        $type  = in_array($typeHint, OfferTypeClassifier::TYPES, true)
            ? $typeHint
            : OfferTypeClassifier::classify(trim($title . ' ' . ($j['raw'] ?? '') . ' ' . ($day ?? '')), $items);
        $conf  = max(0, min(100, (int) round((float) ($j['confidence'] ?? 70))));

        return [
            'found'       => $found && ($title !== '' || $items),
            'title'       => $title !== '' ? $title : null,
            'price'       => $price,
            'currency'    => $cur,
            'description' => self::clean((string) ($j['description'] ?? '')) ?: null,
            'items'       => $items,
            'day'         => $day,
            'type'        => $type,
            'confidence'  => $conf,
        ];
    }

    public static function fromText(string $text): array
    {
        $raw  = self::clean($text);
        $price = self::priceFrom($raw);
        $cur   = self::currencyFrom($raw);
        $day   = self::dayFrom($raw);
        $items = self::normItems(self::splitItems($raw));
        $title = self::titleFrom($raw);
        $type  = OfferTypeClassifier::classify($raw, $items);

        return [
            'found'       => $title !== '' || $price !== null || count($items) >= 2,
            'title'       => $title !== '' ? $title : null,
            'price'       => $price,
            'currency'    => $cur,
            'description' => null,
            'items'       => $items,
            'day'         => $day,
            'type'        => $type,
            'confidence'  => $title !== '' && $price !== null ? 60 : 35,   // deterministic = lower trust
        ];
    }

    /* ----------------------------------------------------------------- helpers */

    public static function priceFrom($v): ?int
    {
        if (is_int($v)) return $v > 0 ? $v : null;
        if (is_float($v)) return $v > 0 ? (int) round($v) : null;
        $s = mb_strtolower((string) $v);
        if ($s === '') return null;
        // pick the largest money-looking number (posters often have phone numbers too — those are longer)
        if (! preg_match_all('/\d{1,3}(?:[,\.]\d{3})+|\d+/', $s, $m)) return null;
        $best = 0;
        foreach ($m[0] as $tok) {
            $digits = preg_replace('/\D/', '', $tok);
            if (strlen($digits) > 7) continue;          // 8+ consecutive digits = phone, not a price
            $n = (int) $digits;
            if ($n >= 100 && $n <= 2000000 && $n > $best) $best = $n;
        }
        return $best > 0 ? $best : null;
    }

    public static function currencyFrom(string $s): ?string
    {
        $t = mb_strtolower($s);
        if (preg_match('/\bugx\b|u?shs?\b|uganda/', $t)) return 'UGX';
        if (preg_match('/\bssp\b|south sudan/', $t)) return 'SSP';
        if (preg_match('/\bkes\b|ksh\b|kenya/', $t)) return 'KES';
        if (preg_match('/\busd\b|\$|dollar/', $t)) return 'USD';
        if (preg_match('/\binr\b|\brs\.?\b|₹|rupee/', $t)) return 'INR';
        return null;
    }

    public static function dayFrom(string $s): ?string
    {
        $t = mb_strtolower($s);
        if (preg_match('/\b(today|aaj|aaje)\b/', $t)) return 'today';
        if (preg_match('/\b(tomorrow|kale|kaale)\b/', $t)) return 'tomorrow';
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
            if (preg_match('/\b' . $d . '\b/', $t)) return $d;
        }
        // short forms
        $map = ['mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday',
                'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday'];
        foreach ($map as $k => $v) { if (preg_match('/\b' . $k . '\b/', $t)) return $v; }
        return null;
    }

    /** A short product/offer title: the phrase ending in "thali/special/offer", else first strong line. */
    public static function titleFrom(string $raw): string
    {
        $t = trim($raw);
        if (preg_match('/([a-z][a-z\' ]{1,28}?\bthali)\b/i', $t, $m)) return self::cleanTitle($m[1]);
        if (preg_match('/([a-z][a-z\' ]{1,28}?\b(special|offer|combo))\b/i', $t, $m)) return self::cleanTitle($m[1]);
        // else the first line / segment that isn't a day or "menu/lunch" word
        $first = preg_split('/[\r\n;|]+/', $t)[0] ?? $t;
        $first = preg_replace('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|lunch|dinner|menu|today)\b/i', ' ', $first);
        $first = trim(preg_replace('/\s+/', ' ', $first));
        return self::cleanTitle(mb_substr($first, 0, 40));
    }

    /** Split a flat OCR string into candidate item names on commas / newlines / bullets / dots. */
    private static function splitItems(string $raw): array
    {
        $parts = preg_split('/[,\n\r;|•·\.]+/u', $raw) ?: [];
        return array_map('trim', $parts);
    }

    public static function normItems($items): array
    {
        if (is_string($items)) $items = preg_split('/[,\n\r;|]+/u', $items) ?: [];
        if (! is_array($items)) return [];
        $stop = ['menu', 'lunch', 'dinner', 'today', 'monday', 'tuesday', 'wednesday', 'thursday',
            'friday', 'saturday', 'sunday', 'thali', 'special', 'offer', 'ugx', 'inr', 'kes', 'ssp', 'usd', 'rs'];
        $out = [];
        foreach ($items as $it) {
            $s = self::clean((string) $it);
            $s = trim(preg_replace('/\bugx\b|\binr\b|\bssp\b|\bkes\b|\busd\b|\brs\.?\b|₹/i', '', $s));
            $s = trim(preg_replace('/\d{1,3}(?:[,\.]\d{3})+|\d{3,7}/', '', $s));   // strip prices
            $s = trim(preg_replace('/\s+/', ' ', $s));
            $low = mb_strtolower($s);
            if ($s === '' || mb_strlen($s) < 2) continue;
            if (in_array($low, $stop, true)) continue;
            if (! preg_match('/[a-z]/i', $s)) continue;     // need letters
            $out[$low] = self::titleCase($s);
        }
        return array_values(array_slice($out, 0, 20));
    }

    private static function cleanTitle(string $s): string
    {
        $s = self::clean($s);
        $s = trim(preg_replace('/^((?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|lunch|dinner|menu|today|special)\s+)+/i', '', $s));
        return self::titleCase(mb_substr(trim($s), 0, 60));
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    private static function titleCase(string $s): string
    {
        if ($s === '') return '';
        return preg_replace_callback('/\b([a-z])([a-z\']*)\b/u', fn ($m) => mb_strtoupper($m[1]) . $m[2], $s);
    }
}
