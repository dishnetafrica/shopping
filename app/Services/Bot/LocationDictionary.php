<?php

namespace App\Services\Bot;

/**
 * Built-in local-area awareness for Kampala and Juba.
 *
 * Recognises neighbourhood / suburb names (and common misspellings) in a customer
 * message so they are treated as a DELIVERY LOCATION, not a product search, and so
 * the canonical name can drive zone matching / fee / ETA.
 *
 * Pure & static — no framework dependencies, unit-testable directly.
 */
class LocationDictionary
{
    /** Canonical Kampala areas. */
    public const KAMPALA = [
        'Kisaasi', 'Ntinda', 'Bukoto', 'Kulambiro', 'Kyanja', 'Najjera', 'Bweyogerere',
        'Kireka', 'Kyaliwajjala', 'Mbuya', 'Naguru', 'Kololo', 'Nakasero', 'Wandegeya',
        'Makerere', 'Kawempe', 'Kasangati', 'Gayaza', 'Munyonyo', 'Ggaba', 'Makindye',
        'Muyenga', 'Luzira', 'Bugolobi', 'Namugongo', 'Seeta', 'Nansana', 'Mengo',
        'Rubaga', 'Ndejje', 'Buziga', 'Upper Mawanda', 'Lower Mawanda',
    ];

    /** Canonical Juba areas. */
    public const JUBA = [
        'Munuki', 'Jebel', 'Hai Malakal', 'Gudele', 'Konyo Konyo', 'Atlabara', 'Thongpiny',
        'New Site', 'Custom', 'Juba Town', 'Hai Cinema', 'Hai Referendum', 'Rock City', 'Tongping',
    ];

    /** Misspellings / alternative spellings -> canonical name (keys lowercased). */
    public const ALIASES = [
        // Kampala
        'kisasi' => 'Kisaasi', 'kissasi' => 'Kisaasi', 'kisaasi' => 'Kisaasi', 'kisasi' => 'Kisaasi',
        'tinda' => 'Ntinda', 'ntinida' => 'Ntinda',
        'najera' => 'Najjera', 'najeera' => 'Najjera', 'najjera' => 'Najjera',
        'bweyogereree' => 'Bweyogerere', 'bweyo' => 'Bweyogerere', 'bweyogerere' => 'Bweyogerere',
        'kyaliwajala' => 'Kyaliwajjala', 'kyaliwajara' => 'Kyaliwajjala',
        'gaba' => 'Ggaba', 'gabba' => 'Ggaba',
        'muyega' => 'Muyenga',
        'namugongoo' => 'Namugongo',
        'kulambilo' => 'Kulambiro',
        'kasangat' => 'Kasangati',
        'nakawa' => 'Bugolobi', // common adjacency shorthand customers use
        'wandegeya' => 'Wandegeya', 'wandageya' => 'Wandegeya',
        'kawempe' => 'Kawempe',
        // Juba
        'tongping' => 'Thongpiny', 'tongpiny' => 'Thongpiny', 'thongping' => 'Thongpiny',
        'jabel' => 'Jebel', 'jebal' => 'Jebel',
        'gudere' => 'Gudele',
        'konyokonyo' => 'Konyo Konyo',
        'munuki' => 'Munuki',
        'atalabara' => 'Atlabara', 'atlabara' => 'Atlabara',
    ];

    /** Common English words that are also area names — require a cue / whole-message match. */
    private const AMBIGUOUS = ['Custom'];

    private static ?array $formsCache = null;
    private static ?array $cityCache  = null;

    /** Lowercase, strip punctuation, collapse whitespace. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9\s]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** canonical => city map. */
    private static function cities(): array
    {
        if (self::$cityCache !== null) return self::$cityCache;
        $m = [];
        foreach (self::KAMPALA as $a) $m[$a] = 'Kampala';
        foreach (self::JUBA as $a)    $m[$a] = 'Juba';
        return self::$cityCache = $m;
    }

    public static function cityOf(string $canonical): ?string
    {
        return self::cities()[$canonical] ?? null;
    }

    /**
     * Searchable forms: [normalisedForm, canonical, city], longest phrase first so
     * "konyo konyo" / "upper mawanda" match before a shorter contained word.
     */
    private static function forms(): array
    {
        if (self::$formsCache !== null) return self::$formsCache;
        $cities = self::cities();
        $forms = [];
        foreach (array_keys($cities) as $canon) {
            $forms[self::norm($canon)] = $canon;            // canonical itself
        }
        foreach (self::ALIASES as $alias => $canon) {
            $forms[self::norm($alias)] = $canon;            // alias spellings
        }
        $out = [];
        foreach ($forms as $form => $canon) {
            if ($form === '') continue;
            $out[] = [$form, $canon, $cities[$canon] ?? null, str_word_count($form)];
        }
        usort($out, fn ($a, $b) => ($b[3] <=> $a[3]) ?: (mb_strlen($b[0]) <=> mb_strlen($a[0])));
        return self::$formsCache = array_map(fn ($r) => [$r[0], $r[1], $r[2]], $out);
    }

    /** @return array{area:string,city:?string,match:string}|null */
    public static function detect(string $text): ?array
    {
        $t = ' ' . self::norm($text) . ' ';
        if (trim($t) === '') return null;
        foreach (self::forms() as [$form, $canon, $city]) {
            if (str_contains($t, ' ' . $form . ' ')) {
                return ['area' => $canon, 'city' => $city, 'match' => $form];
            }
        }
        return null;
    }

    /** Explicit location phrasing ("deliver to", "am in", "near", "plot", "road"...). */
    public static function hasCue(string $text): bool
    {
        $lc = ' ' . self::norm($text) . ' ';
        return (bool) preg_match(
            '/\b(deliver(?:y)? (?:to|at)|am (?:in|at)|i am (?:in|at)|im (?:in|at)|located (?:in|at)|'
          . 'my (?:location|area|place)|location is|send (?:to|it to)|bring (?:to|it to)|'
          . 'drop (?:at|it at)|near|around|next to|opposite|behind|plot|road|stage|roundabout|'
          . 'avenue|street|junction)\b/',
            $lc
        );
    }

    /** Landmark hint words (used with a cue when no known area is present). */
    public static function hasLandmark(string $text): bool
    {
        $lc = ' ' . self::norm($text) . ' ';
        return (bool) preg_match(
            '/\b(total|shell|stabex|station|petrol|market|church|mosque|hospital|clinic|school|'
          . 'hotel|mall|junction|roundabout|stage|plot|road|avenue|street|lane|crescent|close|bridge)\b/',
            $lc
        );
    }

    private static function isWholeArea(string $text, string $matchForm): bool
    {
        return self::norm($text) === $matchForm;
    }

    /**
     * Should this message be treated as a delivery location?
     * - a known area name (canonical or misspelling) → yes
     *   (an ambiguous common-word area needs a cue or to be the whole message)
     * - otherwise: a location cue together with a landmark hint
     */
    public static function looksLikeLocation(string $text): bool
    {
        $det = self::detect($text);
        if ($det) {
            if (in_array($det['area'], self::AMBIGUOUS, true)) {
                return self::hasCue($text) || self::isWholeArea($text, $det['match']);
            }
            return true;
        }
        return self::hasCue($text) && self::hasLandmark($text);
    }

    /**
     * Clean string for zone matching: the canonical area when recognised, else the
     * original text (so a known misspelling like "Kisasi" becomes "Kisaasi").
     */
    public static function canonicalize(string $text): string
    {
        $det = self::detect($text);
        return $det ? $det['area'] : trim($text);
    }
}
