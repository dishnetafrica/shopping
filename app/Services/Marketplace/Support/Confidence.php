<?php

namespace App\Services\Marketplace\Support;

/**
 * How well does a marketplace product NAME answer what the customer TYPED?
 *
 * Deterministic, dependency-free, and unit-tested so the "did you mean?" gate
 * behaves the same everywhere. Score is 0..1:
 *
 *   - token coverage: fraction of the customer's meaningful words that appear
 *     in the product name (whole-word or stem/substring match),
 *   - a small bonus when the whole query phrase is contained in the name.
 *
 * We do NOT try to be clever about semantics — a shop owner would rather the
 * bot ask "did you mean X?" than confidently quote the wrong item.
 */
final class Confidence
{
    /** Words that carry no product meaning and shouldn't count toward coverage. */
    private const STOPWORDS = [
        'a','an','the','of','for','with','and','or','to','in','on','me','my','i',
        'you','please','pls','want','need','get','buy','order','do','have','you',
        'is','are','it','this','that','some','any','new','original','genuine',
        'price','cost','how','much','send','show','photo','pic','picture',
    ];

    /** @return float 0..1 */
    public static function score(string $query, string $name): float
    {
        $qTokens = self::tokens($query);
        $nName   = self::normalize($name);
        $nTokens = self::tokens($name);

        if ($qTokens === [] || $nTokens === []) {
            return 0.0;
        }

        $matched = 0;
        foreach ($qTokens as $qt) {
            if (self::tokenInName($qt, $nTokens, $nName)) {
                $matched++;
            }
        }
        $coverage = $matched / count($qTokens);

        // Phrase bonus: the full typed phrase sits inside the product name.
        $phrase = trim(self::normalize($query));
        $bonus  = ($phrase !== '' && str_contains($nName, $phrase)) ? 0.15 : 0.0;

        return min(1.0, round($coverage + $bonus, 4));
    }

    /**
     * Bucket a score against the configured thresholds.
     *
     * @return string 'high' | 'medium' | 'low'
     */
    public static function classify(float $score, float $high, float $medium): string
    {
        if ($score >= $high)   return 'high';
        if ($score >= $medium) return 'medium';
        return 'low';
    }

    // ---- internals ----

    private static function tokenInName(string $qt, array $nTokens, string $nName): bool
    {
        if (in_array($qt, $nTokens, true)) {
            return true;                       // whole-word hit
        }
        if (mb_strlen($qt) >= 3 && str_contains($nName, $qt)) {
            return true;                       // "router" inside "routers"/"router-x"
        }
        // stem-ish: match on a 4+ char prefix so "charger" ~ "chargers", "cable" ~ "cabled"
        if (mb_strlen($qt) >= 4) {
            $stem = mb_substr($qt, 0, mb_strlen($qt) - 1);
            foreach ($nTokens as $nt) {
                if (str_starts_with($nt, $stem)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;   // drop punctuation
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim((string) $s);
    }

    /** @return array<int,string> meaningful, de-duplicated lowercase tokens */
    private static function tokens(string $s): array
    {
        $parts = array_filter(explode(' ', self::normalize($s)), fn ($w) => $w !== '');
        $out = [];
        foreach ($parts as $w) {
            if (in_array($w, self::STOPWORDS, true)) continue;
            if (mb_strlen($w) < 2) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }
}
