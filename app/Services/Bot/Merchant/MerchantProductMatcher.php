<?php
namespace App\Services\Bot\Merchant;

/**
 * Pure fuzzy matcher used when an owner's product line has no exact catalogue hit.
 * It decides whether the owner most likely mistyped an EXISTING product (so we
 * should propose an UPDATE) or is naming a genuinely NEW product (so we propose a
 * CREATE). Levenshtein on normalized strings — no DB, fully unit-testable.
 *
 * The owner confirms the proposal either way, so this only steers the default;
 * it never silently overwrites or creates.
 */
class MerchantProductMatcher
{
    /**
     * Closest existing name to $input.
     * @param string[] $names existing product names (tenant-scoped, passed in by the caller)
     * @return array{name:string,distance:int,ratio:float}|null
     */
    public static function closest(string $input, array $names): ?array
    {
        $a = self::norm($input);
        if ($a === '' || ! $names) return null;

        $best = null;
        foreach ($names as $name) {
            $b = self::norm((string) $name);
            if ($b === '') continue;
            $d = levenshtein($a, $b);
            $ratio = $d / max(strlen($a), strlen($b));
            if ($best === null || $d < $best['distance']) {
                $best = ['name' => (string) $name, 'distance' => $d, 'ratio' => $ratio];
            }
        }
        return $best;
    }

    /**
     * Treat $match as a typo of an existing product (=> UPDATE) rather than a new
     * product (=> CREATE). Conservative: small absolute edit distance AND a small
     * proportion of the word changed. e.g. "fafada"->"fafda" (d=1) is a typo;
     * "banana crisps salted" vs anything unrelated is not.
     *
     * @param array{name:string,distance:int,ratio:float}|null $match
     */
    public static function isTypo(?array $match): bool
    {
        if ($match === null) return false;
        return $match['distance'] <= 2 && $match['ratio'] <= 0.34;
    }

    /** digits/letters only, lowercased, spaces collapsed */
    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
        return $s;
    }
}
