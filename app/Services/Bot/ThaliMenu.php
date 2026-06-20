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

    /** Does this shop run a separate night (dinner) menu? */
    public static function hasNight(array $cfg): bool
    {
        return ! empty($cfg['night_enabled']) && ! empty($cfg['night_days']);
    }

    /** Normalise a day's dish list (trim, drop blanks). */
    public static function cleanItems($items): array
    {
        return is_array($items)
            ? array_values(array_filter(array_map(fn ($x) => trim((string) $x), $items), fn ($x) => $x !== ''))
            : [];
    }

    /**
     * Which session ('day' or 'night') applies now. Night only kicks in when the
     * shop runs a night menu, the hour has passed the switch time, AND there are
     * dinner dishes set for today (otherwise we stay on the lunch menu). A caller
     * can force a session (e.g. the customer asked for "dinner").
     */
    public static function session(array $cfg, string $tz, ?string $force = null): string
    {
        if (! self::hasNight($cfg)) return 'day';
        if ($force === 'day' || $force === 'night') return $force;
        $sw = (int) ($cfg['switch_hour'] ?? 16);
        try {
            $h = (int) (new \DateTime('now', new \DateTimeZone($tz)))->format('G');
        } catch (\Throwable $e) {
            $h = (int) date('G');
        }
        $nightToday = self::cleanItems($cfg['night_days'][self::todayKey($tz)] ?? []);
        return ($h >= $sw && $nightToday) ? 'night' : 'day';
    }

    /** Detect an explicit lunch/dinner mention in the customer's text. */
    public static function sessionFromText(string $text): ?string
    {
        $lc = mb_strtolower($text);
        if (preg_match('/\b(dinner|night|evening|supper|nite)\b/u', $lc)) return 'night';
        if (preg_match('/\b(lunch|afternoon|noon|midday)\b/u', $lc)) return 'day';
        return null;
    }

    /**
     * Resolve the dishes/price/note/label for a given day + session, with sensible
     * fallbacks: a dinner with no dishes for that day falls back to the lunch menu,
     * and a missing night price/note inherits the day's.
     * Returns [items[], price, note, label].
     */
    public static function resolve(array $cfg, string $dayKey, string $session = 'day'): array
    {
        $hasNight = self::hasNight($cfg);
        $useNight = ($session === 'night' && $hasNight)
            && self::cleanItems($cfg['night_days'][$dayKey] ?? []) !== [];

        if ($useNight) {
            $items = self::cleanItems($cfg['night_days'][$dayKey] ?? []);
            $price = (int) ($cfg['night_price'] ?? 0); if ($price <= 0) $price = (int) ($cfg['price'] ?? 0);
            $note  = trim((string) ($cfg['night_note'] ?? '')); if ($note === '') $note = trim((string) ($cfg['note'] ?? ''));
            $label = self::dayName($dayKey) . ($hasNight ? ' Dinner' : '');
        } else {
            $items = self::cleanItems($cfg['days'][$dayKey] ?? []);
            $price = (int) ($cfg['price'] ?? 0);
            $note  = trim((string) ($cfg['note'] ?? ''));
            $label = self::dayName($dayKey) . ($hasNight ? ' Lunch' : '');
        }
        return [$items, $price, $note, $label];
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

    /** Build the customer-facing menu reply for one day (and optional session). */
    public static function render(array $cfg, string $dayKey, string $cur, string $session = 'day'): string
    {
        [$items, $price, $note, $label] = self::resolve($cfg, $dayKey, $session);

        if (! $items) {
            return "\u{1F37D}\u{FE0F} Our *Kathiyawadi Thali* is served Monday\u{2013}Saturday"
                . ($price ? " ({$cur} " . number_format($price) . ")" : "")
                . ". There's no thali set for {$label} \u{2014} please check another day or ask us.";
        }

        $lines = "\u{1F37D}\u{FE0F} *{$label} Kathiyawadi Thali*"
            . ($price ? " \u{2014} {$cur} " . number_format($price) : "") . " (pure veg)\n";
        foreach ($items as $it) {
            $lines .= "\u{2022} {$it}\n";
        }
        if ($note !== '') $lines .= "\n_{$note}_\n";
        $lines .= "\nReply *add thali* to order it";
        return $lines;
    }

    /** Loosely split an add/remove request into pieces for a friendly echo. Raw text is the source of truth. */
    public static function parseModification(string $text): array
    {
        $lc = ' ' . mb_strtolower($text) . ' ';
        $clean = function (string $s): string {
            $s = trim(preg_replace('/\s+/', ' ', $s));
            $s = trim($s, " .,;:-");
            return $s;
        };
        $stop = '(?=\s*(?:,|\.|;|and\b|but\b|also\b|plus\b|add\b|extra\b|remove\b|without\b|instead\b|in place\b|$))';
        $removes = [];
        $adds = [];
        if (preg_match_all('/\b(?:remove|without|no|skip|don\'?t want|do not want|take out|leave out)\s+(?:the\s+)?([a-z][a-z0-9 &]{1,28}?)' . $stop . '/', $lc, $m)) {
            foreach ($m[1] as $x) { $x = $clean($x); if ($x !== '') $removes[] = $x; }
        }
        if (preg_match_all('/\b(?:add|include|put|give me|i want|want|extra|more)\s+(?:some\s+|an?\s+)?([a-z][a-z0-9 &]{1,28}?)' . $stop . '/', $lc, $m)) {
            foreach ($m[1] as $x) {
                $x = $clean($x);
                if ($x !== '' && ! in_array($x, ['to', 'it', 'that', 'this', 'something', 'else', 'the thali', 'thali'], true)) $adds[] = $x;
            }
        }
        return [
            'remove' => array_values(array_unique($removes)),
            'add'    => array_values(array_unique($adds)),
            'raw'    => trim($text),
        ];
    }

    /**
     * The day + session that apply right now, with optional rollover. Returns
     * ['day'=>key, 'session'=>'day'|'night', 'rollover'=>bool]. Built from the same
     * primitives the rest of the class uses, so it matches session()/todayKey() exactly.
     *
     * Rollover (showing tomorrow's lunch once tonight's service has closed) only kicks in
     * when the shop sets a 'close_hour'; without it, behaviour is identical to today.
     */
    public static function effective(array $cfg, string $tz): array
    {
        $day      = self::todayKey($tz);
        $session  = self::session($cfg, $tz);
        $rollover = false;

        $close = (int) ($cfg['close_hour'] ?? 0);
        if (self::hasNight($cfg) && $close > 0) {
            try {
                $h = (int) (new \DateTime('now', new \DateTimeZone($tz)))->format('G');
            } catch (\Throwable $e) {
                $h = (int) date('G');
            }
            if ($h >= $close) {
                $idx     = array_search($day, self::DAYS, true);
                $day     = self::DAYS[($idx === false ? 0 : $idx + 1) % 7];
                $session = 'day';
                $rollover = true;
            }
        }

        return ['day' => $day, 'session' => $session, 'rollover' => $rollover];
    }

    /** Does this day have BOTH a lunch and a dinner menu set (so the customer can switch)? */
    public static function hasBoth(array $cfg, string $day): bool
    {
        if (! self::hasNight($cfg)) return false;
        $lunch  = self::cleanItems($cfg['days'][$day] ?? []);
        $dinner = self::cleanItems($cfg['night_days'][$day] ?? []);
        return $lunch !== [] && $dinner !== [];
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
