<?php
namespace App\Services\Bot;

/**
 * Multilingual product aliasing (Phase 1, rules only — no LLM).
 *
 * Maps Gujlish / Hindi / mis-spelled product names to a canonical term so the catalogue
 * matcher can find them, instead of the bot saying "we don't stock *aadu*". Pure + unit-tested
 * in qa/intent_router.php. canonical() is the authoritative lookup; normalize() rewrites the
 * known alias tokens inside a message on the product-search fall-through path only.
 */
class ProductAlias
{
    /** canonical => [variants...] (all lowercase). Longest phrases handled first in normalize(). */
    private const ALIASES = [
        'ginger'      => ['aadu', 'adu', 'aadhu', 'aadoo'],
        'gulab jamun' => ['gulab jamun', 'gulab jamuns', 'gulabjamun', 'gulab jambu', 'gulab jamboo'],
        'samosa'      => ['samosa', 'samosha', 'samosas', 'samose', 'samosae'],
        'potato'      => ['bateta', 'batata', 'bataka', 'potato'],
        'tomato'      => ['tameta', 'tamota', 'tametaa', 'tomato'],
        'paneer'      => ['paneer', 'panir', 'panner', 'panneer'],
        'khakhra'     => ['khakhra', 'khakra', 'khakhara', 'khakaraa'],
        'dhokla'      => ['dhokla', 'dhokala', 'dokla', 'dhoklaa'],
    ];

    /** Return the canonical product name for a token/phrase, or null if not a known alias. */
    public static function canonical(string $token): ?string
    {
        $t = trim(mb_strtolower($token));
        if ($t === '') return null;
        foreach (self::ALIASES as $canon => $variants) {
            if ($t === $canon || in_array($t, $variants, true)) {
                return $canon;
            }
        }
        return null;
    }

    /** True if the token/phrase is a known product alias. */
    public static function isKnown(string $token): bool
    {
        return self::canonical($token) !== null;
    }

    /**
     * Rewrite known alias tokens in free text to their canonical form, so the catalogue
     * matcher gets a standard spelling. Conservative: whole-word / whole-phrase only,
     * longest variants first. Applied only on the product-search fall-through.
     */
    public static function normalize(string $text): string
    {
        $pairs = [];
        foreach (self::ALIASES as $canon => $variants) {
            foreach ($variants as $v) {
                if ($v === $canon) continue;
                $pairs[$v] = $canon;
            }
        }
        // Longest variant first so multi-word phrases win over their fragments.
        uksort($pairs, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($pairs as $variant => $canon) {
            $text = preg_replace('/\b' . preg_quote($variant, '/') . '\b/iu', $canon, $text);
        }
        return $text;
    }
}
