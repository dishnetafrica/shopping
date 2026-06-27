<?php
namespace App\Services\Knowledge;

/**
 * Resolves an owner's personal phrasing to canonical tokens so deterministic extraction adapts
 * to each owner today (and AI later). Pure. Owner A "same as yesterday", Owner B "repeat",
 * Owner C "no changes" all map to REPEAT_PREVIOUS.
 */
class OwnerProfileResolver
{
    /** Seeded defaults; an owner's stored aliases_json is merged on top. */
    public const SEED_ALIASES = [
        'same as yesterday' => Intent::REPEAT_PREVIOUS,
        'same as today'     => Intent::REPEAT_PREVIOUS,
        'same like yesterday' => Intent::REPEAT_PREVIOUS,
        'repeat'            => Intent::REPEAT_PREVIOUS,
        'repeat yesterday'  => Intent::REPEAT_PREVIOUS,
        'no change'         => Intent::REPEAT_PREVIOUS,
        'no changes'        => Intent::REPEAT_PREVIOUS,
        'nothing new'       => Intent::REPEAT_PREVIOUS,
        'same'              => Intent::REPEAT_PREVIOUS,
        'same as before'    => Intent::REPEAT_PREVIOUS,
    ];

    /** Merge seed + the owner's learned aliases (owner wins). */
    public static function aliases(array $profile = []): array
    {
        $own = [];
        foreach (($profile['aliases_json'] ?? []) as $k => $v) {
            $own[self::norm((string) $k)] = $v;
        }
        return array_merge(self::SEED_ALIASES, $own);
    }

    /** Canonical token for a whole-message phrase, or null if not an alias. */
    public static function resolve(string $text, array $profile = []): ?string
    {
        $n = self::norm($text);
        $map = self::aliases($profile);
        return $map[$n] ?? null;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z\s]/', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
