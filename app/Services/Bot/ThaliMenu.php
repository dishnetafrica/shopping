<?php

namespace App\Services\Bot;

/**
 * Deterministic helper for a shop's daily set-meal ("thali"). The weekly menu
 * lives in tenant settings ['thali'] so the shop can change it any time:
 *   ['enabled'=>true,'price'=>15000,'note'=>'…','days'=>['mon'=>[...],'tue'=>[...], …]]
 * This class is PURE (no DB/HTTP) so it can be unit-/stress-tested directly.
 */
class ThaliMenu
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const DAY_NAMES = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    /** Is a thali configured + switched on for this tenant config blob? */
    public static function enabled(array $cfg): bool
    {
        return ! empty($cfg) && (bool) ($cfg['enabled'] ?? false) && ! empty($cfg['days']);
    }

    /** Customer is ASKING about the thali menu (not ordering it). Bare "thali" is an order. */
    public static function isMenuQuery(string $text): bool
    {
        $lc = mb_strtolower($text);
        if (! str_contains($lc, 'thali') && ! str_contains($lc, 'special')) {
            // "what's for lunch today" style with no thali word
            return (bool) preg_match('/\b(what.?s|todays|today\'?s|aaj)\b.*\b(lunch|meal|food|special|menu)\b/', $lc);
        }
        // mentions thali/special + a question/menu word
        return (bool) preg_match('/\b(menu|today|todays|to ?day|aaj|what|what.?s|inside|contain|contains|comes?|item|items|veg|price|cost|how much|served|serve|available|special)\b/', $lc);
    }

    /** Customer wants to CHANGE a set meal (handled by a human call before dispatch). */
    public static function isModification(string $text): bool
    {
        $lc = mb_strtolower($text);
        return (bool) preg_match(
            '/\b(without|no (onion|garlic|sugar|sweet|salt|spice|spicy|chilli|chili|curd|dahi|ghee|oil)|'
            . 'extra|less\s+spicy|more\s+spicy|low\s+spice|mild|instead of|in place of|swap|substitute|'
            . 'customi[sz]e|modify|modif|special request|can (i|you|we) (change|swap|add|remove|replace)|'
            . 'change the|remove the|replace|add more|make it (less|more|mild|spicy)|extra (roti|rotli|chapati|chappati|rice|sabji|gravy|dal|daal|papad|sweet|gulab))\b/',
            $lc
        );
    }

    /** Map a named day in the text to a key, else null. */
    public static function dayFromText(string $text): ?string
    {
        $lc = mb_strtolower($text);
        foreach (self::DAY_NAMES as $k => $name) {
            if (str_contains($lc, mb_strtolower($name)) || preg_match('/\b' . $k . '\b/', $lc)) return $k;
        }
        if (preg_match('/\b(today|todays|today\'?s|aaj)\b/', $lc)) return null; // means "today"
        return null;
    }

    /** Weekday key for "today" in a timezone (1=Mon … 7=Sun). */
    public static function todayKey(string $tz): string
    {
        try {
            $w = (int) (new \DateTime('now', new \DateTimeZone($tz)))->format('N');
        } catch (\Throwable $e) {
            $w = (int) date('N');
        }
        return self::DAYS[max(0, min(6, $w - 1))];
    }

    public static function dayName(string $key): string
    {
        return self::DAY_NAMES[$key] ?? ucfirst($key);
    }

    /** Build the customer-facing menu reply for one day. */
    public static function render(array $cfg, string $dayKey, string $cur): string
    {
        $price = (int) ($cfg['price'] ?? 0);
        $note  = trim((string) ($cfg['note'] ?? ''));
        $items = $cfg['days'][$dayKey] ?? [];
        $items = is_array($items) ? array_values(array_filter(array_map('trim', $items))) : [];
        $day   = self::dayName($dayKey);

        if (! $items) {
            return "\u{1F37D}\u{FE0F} Our *Kathiyawadi Thali* is served Monday\u{2013}Saturday"
                . ($price ? " ({$cur} " . number_format($price) . ")" : "")
                . ". There's no thali set for {$day} \u{2014} please check another day or ask us.";
        }

        $lines = "\u{1F37D}\u{FE0F} *{$day} Kathiyawadi Thali*"
            . ($price ? " \u{2014} {$cur} " . number_format($price) : "") . " (pure veg)\n";
        foreach ($items as $it) {
            $lines .= "\u{2022} {$it}\n";
        }
        if ($note !== '') $lines .= "\n_{$note}_\n";
        $lines .= "\nReply *add thali* to order it";
        return $lines;
    }

    /** Flyer-seeded weekly menu (Mon–Sat) for Pal's — used to seed tenant settings. */
    public static function palsSeed(): array
    {
        return [
            'enabled' => true,
            'price'   => 15000,
            'note'    => 'Pure veg · menu changes daily · a complete meal of tradition & taste',
            'days'    => [
                'mon' => ['Rajwadi Dhokli', 'Mag Sabji', '5 Chappati', 'Daal Rice', 'Salad, Papad & Chaas'],
                'tue' => ['Chana Sabji', 'Ringan Bateta Mix', '5 Chappati', 'Daal Rice', 'Salad, Papad & Chaas'],
                'wed' => ['Rajma Sabji', 'Lasaniya Bateta', '5 Chappati', 'Kadhi Rice', 'Salad, Papad & Chaas'],
                'thu' => ['Bharelo Bhindo', 'Suki Chori ni Sabji', '5 Chappati', 'Pulav Rayta', 'Salad, Papad & Chaas'],
                'fri' => ['Chole Bhature', 'Parotha', 'Paneer Sabji', 'Dalfry Jira Rice', 'Salad, Papad & Chaas'],
                'sat' => ['Puri', 'Bateta Sabji', 'Gulab Jamun', 'Dal Rice', 'Salad, Papad & Chaas'],
                'sun' => [],
            ],
        ];
    }
}
