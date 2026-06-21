<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — opening hours, promotions, daily-menu patterns. Pure logic.
 */
class PatternMiner
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** Opening hours from owner statements (and a fallback to activity hours). */
    public static function hours(MessageCorpus $corpus): array
    {
        $text = $corpus->ownerText();
        $out  = ['text' => null, 'closed_days' => [], 'confidence' => 0];
        $hits = 0;

        if (preg_match('/(?:open|hours?)\D{0,16}(\d{1,2}\s*(?::\d{2})?\s*(?:am|pm)?)\s*(?:to|-|till|until|\x{2013})\s*(\d{1,2}\s*(?::\d{2})?\s*(?:am|pm)?)/u', $text, $m)) {
            $out['text'] = trim($m[1]) . ' – ' . trim($m[2]); $hits += 2;
        } elseif (str_contains($text, 'open 24') || str_contains($text, '24 hours') || str_contains($text, '24/7')) {
            $out['text'] = '24 hours'; $hits += 2;
        } elseif (str_contains($text, 'open daily') || str_contains($text, 'everyday')) {
            $out['text'] = 'Open daily'; $hits++;
        }

        foreach (self::DAYS as $d) {
            if (preg_match('/closed (?:on )?' . $d . '|' . $d . '\s+closed|no service (?:on )?' . $d . '/u', $text)) {
                $out['closed_days'][] = ucfirst($d); $hits++;
            }
        }

        // fallback: hour span of owner activity from timestamps
        if ($out['text'] === null) {
            $span = self::activitySpan($corpus);
            if ($span) { $out['text'] = $span . ' (from activity)'; $hits++; }
        }

        $out['confidence'] = min(90, $hits * 25);
        return $out;
    }

    /** Promotion phrases the owner repeats. */
    public static function promotions(MessageCorpus $corpus): array
    {
        $out = [];
        foreach ($corpus->owner as $msg) {
            $m = mb_strtolower($msg);
            if (preg_match('/(\d{1,3})\s*%\s*(?:off|discount)/u', $m, $g)) {
                $out[] = ['type' => 'discount', 'detail' => $g[1] . '% off', 'sample' => self::clip($msg)];
            } elseif (preg_match('/buy\s*\d+\s*get\s*\d+/u', $m, $g)) {
                $out[] = ['type' => 'combo', 'detail' => trim($g[0]), 'sample' => self::clip($msg)];
            } elseif (preg_match('/(today special|special offer|offer of the day|aaj ka offer|leo special)/u', $m, $g)) {
                $out[] = ['type' => 'daily', 'detail' => trim($g[1]), 'sample' => self::clip($msg)];
            }
        }
        return self::dedupBy($out, fn ($r) => $r['type'] . '|' . $r['detail'], 8);
    }

    /** Recurring daily-menu / thali posts, grouped by weekday when stated. */
    public static function menuPatterns(MessageCorpus $corpus): array
    {
        $out = [];
        foreach ($corpus->owner as $msg) {
            $m = mb_strtolower($msg);
            $isMenu = preg_match('/(today\x27?s? (?:menu|thali|special)|menu of the day|aaj ?nu|aaj ka menu|daily menu|lunch special)/u', $m);
            if (! $isMenu) continue;
            $day = null;
            foreach (self::DAYS as $d) if (str_contains($m, $d)) { $day = ucfirst($d); break; }
            $out[] = ['day' => $day, 'sample' => self::clip($msg)];
        }
        return self::dedupBy($out, fn ($r) => ($r['day'] ?? '') . '|' . $r['sample'], 8);
    }

    private static function activitySpan(MessageCorpus $corpus): ?string
    {
        $hours = [];
        foreach ($corpus->all as $r) {
            if (! ($r['from_owner'] ?? false)) continue;
            $ts = (string) ($r['ts'] ?? '');
            if (preg_match('/(\d{1,2}):(\d{2})/', $ts, $m)) $hours[] = (int) $m[1];
        }
        if (count($hours) < 4) return null;
        sort($hours);
        return sprintf('%02d:00 – %02d:00', $hours[0], end($hours));
    }

    private static function clip(string $s, int $n = 80): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }

    private static function dedupBy(array $rows, callable $key, int $max): array
    {
        $seen = []; $out = [];
        foreach ($rows as $r) {
            $k = $key($r);
            if (isset($seen[$k])) continue; $seen[$k] = true; $out[] = $r;
            if (count($out) >= $max) break;
        }
        return $out;
    }
}
