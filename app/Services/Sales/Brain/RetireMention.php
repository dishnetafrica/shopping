<?php

namespace App\Services\Sales\Brain;

/**
 * PURE. Take a discontinued product or plan out of sales knowledge text (DishNet, 21 Nov 2026: "our system
 * gives quotations for Starlink Roam but that plan is no more — remove it and update the prompt").
 *
 * Line by line, sentence by sentence: an alternative is dropped first ("for roaming / mobile use" → "for mobile
 * use"); a sentence that still mentions the word is dropped; when the sentence has
 * parts separated by ";" only the parts that mention it are dropped ("Roam plan yes; Residential is tied to
 * the service address" → "Residential is tied to the service address"). A question whose whole answer goes
 * keeps nothing — the line is dropped. Everything else is kept exactly as written.
 */
final class RetireMention
{
    /** The word as a whole word, and its -ing form ("Roam", "roaming"), any case. */
    public static function pattern(string $word): string
    {
        return '/\b' . preg_quote(trim($word), '/') . '(?:ing|s)?\b/iu';
    }

    public static function mentions(string $text, string $word): bool
    {
        return trim($word) !== '' && (bool) preg_match(self::pattern($word), $text);
    }

    /** The text without the word. Unchanged when it does not mention it. */
    public static function scrub(string $text, string $word): string
    {
        if (! self::mentions($text, $word)) {
            return $text;
        }
        $out = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (! self::mentions($line, $word)) {
                $out[] = $line;
                continue;
            }
            // Never split after a list number ("2. Roam: …" is one sentence, not "2." and "Roam: …").
            $sentences = preg_split('/(?<=\D[.!?])\s+/u', trim($line)) ?: [];
            $kept = [];
            foreach ($sentences as $s) {
                // "for roaming / mobile use" → "for mobile use": drop only the alternative that is retired.
                $w = preg_quote(trim($word), '/');
                $alt = trim((string) preg_replace(['/\b' . $w . '(?:ing|s)?\s*\/\s*/iu', '/\s*\/\s*\b' . $w . '(?:ing|s)?\b/iu'], '', $s));
                if (! self::mentions($alt, $word)) {
                    $kept[] = $alt === $s ? $s : self::sentence($alt);
                    continue;
                }
                $parts = array_values(array_filter(array_map('trim', explode(';', $s)), fn ($p) => $p !== '' && ! self::mentions($p, $word)));
                if ($parts) {
                    $kept[] = self::sentence(implode('; ', $parts));
                }
            }
            // A question left with no answer ("Can I move Starlink between locations?") says nothing: drop it.
            if ($kept && ! (count($kept) === 1 && str_ends_with($kept[0], '?') && str_ends_with(trim($line), '.'))) {
                $out[] = implode(' ', $kept);
            }
        }

        return implode("\n", $out);
    }

    /**
     * PURE. Structured knowledge (quotation terms items, lists) without the word: every string is scrubbed, and a
     * list item left empty — a string, or a term whose text is gone — is dropped (Codex on #174: terms print from
     * their items, not from the body).
     */
    public static function scrubData($data, string $word)
    {
        if (is_string($data)) {
            return self::scrub($data, $word);
        }
        if (! is_array($data)) {
            return $data;
        }
        $isList = array_is_list($data);
        $out = [];
        foreach ($data as $k => $v) {
            $v = self::scrubData($v, $word);
            if ($isList && ($v === '' || (is_array($v) && array_key_exists('text', $v) && trim((string) $v['text']) === ''))) {
                continue;
            }
            $out[$k] = $v;
        }

        return $isList ? array_values($out) : $out;
    }

    /** PURE. Does a knowledge entry mention the word anywhere the bot or a quotation reads it — title, text or data? */
    public static function entryMentions(string $title, ?string $body, $data, string $word): bool
    {
        return self::mentions($title, $word) || self::mentions((string) $body, $word)
            || self::mentions((string) json_encode($data, JSON_UNESCAPED_UNICODE), $word);
    }

    /** Capital first letter and a full stop, after parts were cut out. */
    private static function sentence(string $s): string
    {
        $s = trim($s);
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);

        return preg_match('/[.!?]$/u', $s) ? $s : $s . '.';
    }
}
