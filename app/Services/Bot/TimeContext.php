<?php declare(strict_types=1);

namespace App\Services\Bot;

/**
 * v4.77 (M-CHAT-0, ADR-018) — the brain gets a clock.
 *
 * PURE. Strings and a DateTimeImmutable in, verdicts out. No models, no settings reads,
 * no now() — the CALLER supplies the moment, which is what makes every rule here
 * testable to the minute.
 *
 * Why this exists: the AI prompt had NO current time, NO timezone and NO business hours —
 * "are you open now?" and "deliver tomorrow" were unanchored guesses, on WhatsApp, today.
 * (The owner's web-chat spec demanded time context for the new channel; inspection showed
 * the OLD channel never had it either.)
 *
 * The honesty rule carried over from v4.71: parse what can be parsed, and when the owner's
 * business_hours text cannot be understood, say UNKNOWN — the prompt then instructs the
 * model to quote the hours as written and never to invent open/closed.
 */
final class TimeContext
{
    /** City → IANA zone. Juba is UTC+2 (CAT) since 2021; Kampala is UTC+3 (EAT). */
    public const CITY_ZONES = [
        'juba'          => 'Africa/Juba',
        'kampala'       => 'Africa/Kampala',
        'nairobi'       => 'Africa/Nairobi',
        'kigali'        => 'Africa/Kigali',
        'dar es salaam' => 'Africa/Dar_es_Salaam',
        'addis ababa'   => 'Africa/Addis_Ababa',
        'khartoum'      => 'Africa/Khartoum',
    ];

    public const DEFAULT_ZONE = 'Africa/Kampala';

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Explicit timezone setting wins; else the shop's city; else the platform default. */
    public static function zone(string $tzSetting, string $city): string
    {
        $tz = trim($tzSetting);
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }
        $c = mb_strtolower(trim($city));
        foreach (self::CITY_ZONES as $name => $zone) {
            if ($c !== '' && str_contains($c, $name)) {
                return $zone;
            }
        }

        return self::DEFAULT_ZONE;
    }

    /**
     * Parse the owner's free-text business_hours into a week map of minute ranges,
     * or null when the text cannot be understood (unknown ≠ closed ≠ open).
     *
     * @return array<string, array<int, array{0:int,1:int}>>|null  e.g. ['mon' => [[480,1080]], …]
     */
    public static function parseHours(string $text): ?array
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }
        $t = str_replace(['–', '—', ' to '], ['-', '-', '-'], $t);
        $t = (string) preg_replace('/\b(a\.m\.|am\.)/', 'am', $t);
        $t = (string) preg_replace('/\b(p\.m\.|pm\.)/', 'pm', $t);

        $week = array_fill_keys(self::DAYS, []);
        if (preg_match('/\b(24\s*\/\s*7|24\s*hours|always\s+open|open\s+24)\b/u', $t)) {
            foreach (self::DAYS as $d) {
                $week[$d] = [[0, 1440]];
            }

            return $week;
        }

        $any = false;
        foreach (preg_split('/[,;·\n]+/u', $t) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            $days = self::segDays($seg);
            if (preg_match('/\bclosed?\b/u', $seg)) {
                continue;              // closed days get no ranges; closed ALONE proves nothing
            }
            $range = self::segRange($seg);
            if ($range === null) {
                continue;
            }
            $any = true;
            [$s, $e] = $range;
            foreach ($days ?? self::DAYS as $d) {
                if ($e > $s) {
                    $week[$d][] = [$s, $e];
                } else {                               // overnight, e.g. 18:00-02:00
                    $week[$d][] = [$s, 1440];
                    $week[self::DAYS[(array_search($d, self::DAYS, true) + 1) % 7]][] = [0, $e];
                }
            }
        }

        return $any ? $week : null;
    }

    /** Which days a segment names; null = the segment names none (caller applies daily). */
    private static function segDays(string $seg): ?array
    {
        if (preg_match('/\b(daily|every\s*day|everyday)\b/u', $seg)) {
            return self::DAYS;
        }
        if (preg_match('/\bweek\s*days?\b/u', $seg)) {
            return ['mon', 'tue', 'wed', 'thu', 'fri'];
        }
        if (preg_match('/\bweek\s*ends?\b/u', $seg)) {
            return ['sat', 'sun'];
        }
        $d = '(mon|tue|wed|thu|fri|sat|sun)[a-z]*';
        if (preg_match('/\b' . $d . '\s*-\s*' . $d . '\b/u', $seg, $m)) {
            $a = array_search($m[1], self::DAYS, true);
            $b = array_search($m[2], self::DAYS, true);
            $out = [];
            for ($i = $a; ; $i = ($i + 1) % 7) {
                $out[] = self::DAYS[$i];
                if ($i === $b) {
                    break;
                }
            }

            return $out;
        }
        if (preg_match_all('/\b' . $d . '\b/u', $seg, $m) && $m[1] !== []) {
            return array_values(array_unique($m[1]));
        }

        return null;
    }

    /** The segment's time range in minutes-of-day, or null. */
    private static function segRange(string $seg): ?array
    {
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/u', $seg, $m)) {
            return null;
        }
        $h1 = (int) $m[1]; $m1 = (int) (($m[2] ?? '') ?: 0); $a1 = $m[3] ?? '';
        $h2 = (int) $m[4]; $m2 = (int) (($m[5] ?? '') ?: 0); $a2 = $m[6] ?? '';
        if ($h1 > 24 || $h2 > 24 || $m1 > 59 || $m2 > 59) {
            return null;
        }
        // am/pm resolution. "8-6pm" → 8am-6pm. Bare "9-5" → 9:00-17:00 (end before start
        // in a 24h reading means the writer meant afternoon).
        if ($a1 === 'pm' && $h1 < 12) $h1 += 12;
        if ($a1 === 'am' && $h1 === 12) $h1 = 0;
        if ($a2 === 'pm' && $h2 < 12) $h2 += 12;
        if ($a2 === 'am' && $h2 === 12) $h2 = 0;
        if ($a1 === '' && $a2 === '' && $h2 < $h1 && $h2 <= 12) {
            $h2 += 12;                                 // "9-5" → 17:00
        }
        $s = $h1 * 60 + $m1;
        $e = $h2 * 60 + $m2;
        if ($s === $e) {
            return null;
        }

        return [$s, min($e, 1440)];
    }

    /** Open now? true/false — or null when the hours were never understood (unknown). */
    public static function isOpen(?array $week, \DateTimeImmutable $now): ?bool
    {
        if ($week === null) {
            return null;
        }
        $d = self::DAYS[(int) $now->format('N') - 1];
        $min = (int) $now->format('G') * 60 + (int) $now->format('i');
        foreach ($week[$d] ?? [] as [$s, $e]) {
            if ($min >= $s && $min < $e) {
                return true;
            }
        }

        return false;
    }

    /**
     * The prompt block. Everything in it is computed HERE, server-side — the model is told
     * this is the only source of truth for time and that unknown hours stay unknown.
     */
    public static function block(\DateTimeImmutable $nowLocal, string $zone, string $hoursText): string
    {
        $week = self::parseHours($hoursText);
        $open = self::isOpen($week, $nowLocal);
        $openTxt = $open === null ? ($hoursText === '' ? 'unknown (no business hours configured)' : 'unknown (hours text not machine-readable — quote it as written)')
                 : ($open ? 'YES' : 'NO');

        $b  = "CURRENT TIME (server-computed — the ONLY source of truth for dates and time):\n";
        $b .= '- Now: ' . $nowLocal->format('l, j F Y, H:i') . ' (' . $zone . ")\n";
        $b .= '- Today: ' . $nowLocal->format('Y-m-d') . ' · Tomorrow: ' . $nowLocal->modify('+1 day')->format('Y-m-d') . "\n";
        $b .= '- Business hours: ' . ($hoursText !== '' ? $hoursText : 'not configured') . "\n";
        $b .= '- Open right now: ' . $openTxt . "\n";

        return $b;
    }
}
