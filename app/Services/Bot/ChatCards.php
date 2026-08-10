<?php declare(strict_types=1);

namespace App\Services\Bot;

/**
 * v4.80 (M-CHAT-3, ADR-018) — product cards for the web chat, the honest way.
 *
 * PURE. The AI never outputs HTML and never invents a card. The brain's own convention —
 * product names in *asterisks*, EXACT names from the PRODUCTS list (the same convention the
 * photo responder and the ORDER/QUOTE parser already rely on) — is extracted here, and the
 * CALLER looks those names up in the real catalogue. No database row, no card. A wrongly
 * starred phrase costs nothing: it simply matches no product.
 *
 * card() then normalises a matched product's attributes for the UI: a missing price becomes
 * null ("price on request"), never a number; pack/MOQ appear only when the product truly has
 * them — the v4.71 caption discipline, applied to cards.
 */
final class ChatCards
{
    /** Distinct *starred* names across the collected text messages, in order, capped. */
    public static function starredNames(array $messages, int $max = 4): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (($m['type'] ?? '') !== 'text') {
                continue;
            }
            if (! preg_match_all('/\*(?=[^*\n]*\p{L})(\S[^*\n]{1,68}\S)\*/u', (string) ($m['text'] ?? ''), $mm)) {
                continue;
            }
            foreach ($mm[1] as $n) {
                $n = trim($n);
                if ($n === '' || in_array($n, $out, true)) {
                    continue;
                }
                $out[] = $n;
                if (count($out) >= $max) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * v4.82 — tolerant-but-unambiguous name matching. The model is told to use exact names,
     * but case and the trailing "(Carton of 6pc)" style suffix drift in practice. Two names
     * are the same product when they normalise equal: casefolded, trailing parenthetical
     * groups stripped, whitespace collapsed. Spelling differences (Aluminum/Aluminium) are
     * NOT bridged — a guess about which product was meant is worse than no card.
     */
    public static function normalize(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = (string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $n);   // trailing (…) group
        $n = (string) preg_replace('/\s+/u', ' ', $n);

        return trim($n);
    }

    /**
     * One card from REAL product attributes. Nulls mean "the product doesn't carry this" —
     * the UI says "price on request" instead of a made-up figure.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function card(array $p): array
    {
        $price = (float) ($p['price'] ?? 0);
        $pack  = (int) ($p['pack_size'] ?? 0);
        $moq   = (int) ($p['moq'] ?? 0);

        return [
            'id'       => (int) ($p['id'] ?? 0),
            'name'     => trim((string) ($p['name'] ?? '')),
            'image'    => trim((string) ($p['image_url'] ?? '')),
            'price'    => $price > 0 ? $price : null,
            'currency' => trim((string) ($p['currency'] ?? '')),
            'unit'     => trim((string) ($p['unit_label'] ?? '')),
            'pack'     => $pack > 1 ? $pack : null,
            'moq'      => $moq > 1 ? $moq : null,
        ];
    }
}
