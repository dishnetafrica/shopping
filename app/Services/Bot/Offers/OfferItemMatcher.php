<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — match a queried item against an offer's item list. Pure, no deps.
 *
 * A query matches an offer item when its concept tokens are a (non-empty) SUBSET of that
 * item's concepts:
 *   "rice"       {rice}        ⊆ "Dal Rice"  {dal,rice}  -> match
 *   "chaas"      {chaas}       ⊆ "Chaas"      {chaas}     -> match
 *   "tameta sev" {tameta,sev}  ⊄ any                      -> no match
 *   "chapati"    {chapati}     ⊆ "5 Chapati"  {chapati}   -> match, count 5
 */
class OfferItemMatcher
{
    /** @param string[] $offerItems  @return array{display:string,count:?int}|null */
    public static function find(string $queryItem, array $offerItems): ?array
    {
        $q = ItemAliases::concepts($queryItem);
        if (! $q) return null;

        foreach ($offerItems as $it) {
            $it = (string) $it;
            $c = ItemAliases::concepts($it);
            if ($c && self::subset($q, $c)) {
                return ['display' => self::display($it), 'count' => self::countOf($it)];
            }
        }
        return null;
    }

    private static function subset(array $needle, array $hay): bool
    {
        foreach ($needle as $t) {
            if (! in_array($t, $hay, true)) return false;
        }
        return true;
    }

    private static function countOf(string $it): ?int
    {
        return preg_match('/\b(\d{1,3})\b/', $it, $m) ? (int) $m[1] : null;
    }

    /** Clean display string: collapse whitespace, title-case words. */
    private static function display(string $it): string
    {
        $it = trim(preg_replace('/\s+/', ' ', $it));
        return preg_replace_callback('/\p{L}[\p{L}\']*/u', fn ($w) => ucfirst(mb_strtolower($w[0])), $it);
    }
}
