<?php declare(strict_types=1);

namespace App\Services\Bot;

/**
 * v4.71 — when a photo answers better than a paragraph.
 *
 * PURE. Text in, verdicts out. No models, no database, no gateway.
 *
 * ── the conversation that wrote this class ─────────────────────────────────────────────────────
 *
 * A supplier's buyer asked the foil's thickness; the bot answered "typically around 18 microns" —
 * a number that exists nowhere in the product data. Then: "It looks smaller than the one I
 * supply." The customer put a roll ON A SCALE and photographed 707 g, because weight is how
 * professionals compare foil. The bot replied with two paragraphs and no picture.
 *
 * Two rules fall out of that:
 *
 *   1. A COMPARISON turn ("looks smaller than the one I supply") wants OUR product's photo and
 *      OUR on-file facts next to theirs — not prose. Detecting it is a text rule, so it lives
 *      here where it can be tested to death.
 *   2. A SPEC question (thickness / microns / weight / length) is answerable only from data.
 *      Detecting that a turn asks for one lets the caller check what is actually on file —
 *      and a caption builder that CANNOT receive invented facts (it only formats what it is
 *      handed) keeps the honest path the easy path.
 */
final class PhotoCompare
{
    /**
     * Is the customer comparing our product against something they already know?
     *
     * Deliberately narrow: comparative adjective + reference to their own item, or an explicit
     * same-as ask. "Do you supply hotels?" is not a comparison; "smaller portions please" is not
     * a comparison; both burned earlier photo features (31 Jul incidents), so the bar is a
     * PAIRED pattern, never a lone word.
     */
    public static function isComparison(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        // "looks/seems/is smaller|bigger|thinner|… than …"
        if (preg_match('/\b(looks?|seems?|is|are|feels?)\s+(a\s+bit\s+|much\s+|slightly\s+)?(smaller|bigger|larger|thinner|thicker|shorter|longer|lighter|heavier|different|narrow(er)?|wid(er|e))\b/u', $t)) {
            return true;
        }
        // "…than the one I supply/use/buy/sell/have/get" — with or without the adjective clause.
        if (preg_match('/\bthan\s+(the\s+one|what|mine|ours|the\s+ones?)\b/u', $t)) {
            return true;
        }
        if (preg_match('/\bthe\s+one\s+i\s+(supply|use|buy|sell|have|get|stock)\b/u', $t)) {
            return true;
        }
        // "is it the same as mine / same as the one …", "mine is …"
        if (preg_match('/\b(same\s+as|compare[d]?\s+(to|with))\s+(mine|ours|the\s+one|what\s+i)\b/u', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Strip the comparison clause so the product words can be matched.
     * "the foil looks smaller than the one I supply" → "the foil".
     */
    public static function productWords(string $text): string
    {
        $t = ' ' . mb_strtolower(trim($text)) . ' ';
        $t = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t);   // punctuation never reaches the search box
        $t = (string) preg_replace('/\b(looks?|seems?|feels?|is|are)\s+(a\s+bit\s+|much\s+|slightly\s+)?(smaller|bigger|larger|thinner|thicker|shorter|longer|lighter|heavier|different|narrower|wider)\b.*$/u', ' ', $t);
        $t = (string) preg_replace('/\bthan\s+.*$/u', ' ', $t);
        $t = (string) preg_replace('/\b(same\s+as|compared?\s+(to|with))\b.*$/u', ' ', $t);
        $t = (string) preg_replace('/\b(the|a|an|my|your|our|it|this|that|one|ones)\b/u', ' ', $t);

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * Does the turn ask for a physical specification?
     *
     * These are the questions that CANNOT be answered from a name and a price — only from a spec
     * on file or a tape measure. The caller uses this to decide between "quote the spec we hold"
     * and "say we'll confirm, and send the photo".
     */
    public static function asksSpec(string $text): bool
    {
        $t = mb_strtolower($text);

        return (bool) preg_match(
            '/\b(thickness|thick|micron[s]?|gsm|gauge|ply|how\s+(heavy|wide|tall|big|thick)|how\s+long\s+is|'
            . 'weight|weigh[s]?|grams?|\bkg\b|length|width|diameter|dimensions?|size\s+in\s+(cm|mm|m)|'
            . 'how\s+many\s+(met(er|re)s?|cm|mm))\b/u',
            $t
        );
    }

    /**
     * The caption that rides with a comparison photo — built ONLY from facts handed in.
     *
     * There is no argument for a guessed value to arrive through: the caller passes the specs it
     * found on file (possibly none), and this formats them. When nothing is on file it says so and
     * offers the two honest moves — a photo comparison and a visit — instead of an estimate.
     *
     * @param array<int,array{0:string,1:string}> $specs  on-file specs as [label, value] pairs
     */
    public static function caption(string $name, array $specs, string $priceLine, string $invite): string
    {
        $out = '*' . trim($name) . '*';

        $facts = [];
        foreach ($specs as $s) {
            $l = trim((string) ($s[0] ?? ''));
            $v = trim((string) ($s[1] ?? ''));
            if ($l !== '' && $v !== '') {
                $facts[] = $l . ': ' . $v;
            }
        }
        if ($facts !== []) {
            $out .= "\n" . implode(' · ', array_slice($facts, 0, 4));
        } else {
            $out .= "\nExact thickness/weight: we\u{2019}ll confirm and send it to you.";
        }

        if (trim($priceLine) !== '') {
            $out .= "\n" . trim($priceLine);
        }
        if (trim($invite) !== '') {
            $out .= "\n" . trim($invite);
        }

        return $out;
    }
}
