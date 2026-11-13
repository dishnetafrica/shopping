<?php

namespace App\Services\Travel\Enquiry;

/**
 * Turns what a customer typed ("Dubai", "entebbe", "LHR", "London UK", "Heathrow") into a place a
 * flight search can use. PURE: no database, no framework. The data is a bundled PHP array built from
 * the OurAirports open data (public domain) by scripts/travel-airports-build.py.
 *
 * It never guesses. Three outcomes:
 *   ok        one place: a city with one or more airports, or a single airport
 *   ambiguous the same name exists in more than one country and neither clearly dominates — the
 *             caller asks the customer which one
 *   unknown   nothing matched — the caller asks for the city
 *
 * Several airports in ONE city (London: LHR, LGW, STN…) is not ambiguity: the place is the city and
 * all its airports are kept, largest first, so a later search can cover them all.
 */
final class AirportDirectory
{
    /** Everyday names customers use that are not the city or airport name in the data. */
    private const ALIASES = [
        'kampala'      => 'EBB',            // Kampala's airport is Entebbe
        'kla'          => 'EBB',
        'kili'         => 'JRO',
        'kilimanjaro'  => 'JRO',
        'joburg'       => 'johannesburg',
        'jo burg'      => 'johannesburg',
        'jozi'         => 'johannesburg',
        'addis'        => 'addis ababa',
        'dar'          => 'dar es salaam',
        'nyc'          => 'new york',
        'bombay'       => 'mumbai',
        // Destinations people name by the island or region, not by the airport's town.
        'goa'          => 'GOI',
        'bali'         => 'DPS',
        'santorini'    => 'JTR',
        'mecca'        => 'JED',
        'makkah'       => 'JED',
        'maldives'     => 'MLE',
    ];

    /** How an alias is shown back to the customer when their word is not a tidy place name. */
    private const ALIAS_DISPLAY = ['kla' => 'Kampala', 'kili' => 'Kilimanjaro', 'mecca' => 'Makkah'];

    /** Country words a customer may add after a city ("London UK"). key => ISO code. */
    private const COUNTRY_ALIASES = [
        'uk' => 'GB', 'england' => 'GB', 'britain' => 'GB', 'great britain' => 'GB', 'scotland' => 'GB',
        'usa' => 'US', 'us' => 'US', 'america' => 'US', 'united states' => 'US',
        'uae' => 'AE', 'emirates' => 'AE', 'drc' => 'CD', 'congo' => 'CD', 'south sudan' => 'SS',
        'ivory coast' => 'CI', 'tanzania' => 'TZ', 'russia' => 'RU', 'turkey' => 'TR',
    ];

    private const SIZE_RANK = ['L' => 3, 'M' => 2, 'S' => 1];

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:string}> iata => row */
    private array $byCode = [];
    /** @var array<string,string[]> city key => iata codes */
    private array $byCity = [];
    /** @var array<string,string> ISO => country name */
    private array $countries = [];
    /** @var array<string,string> country-name key => ISO */
    private array $countryKeys = [];

    private static ?self $shared = null;

    public function __construct(?array $data = null)
    {
        $data ??= require __DIR__ . '/../Data/airports.php';
        $this->countries = (array) ($data['countries'] ?? []);
        foreach ($this->countries as $iso => $name) {
            $this->countryKeys[self::key((string) $name)] = (string) $iso;
        }
        foreach (self::COUNTRY_ALIASES as $k => $iso) {
            $this->countryKeys[$k] = $iso;
        }
        foreach ((array) ($data['airports'] ?? []) as $row) {
            $this->byCode[(string) $row[0]] = $row;
            $this->byCity[self::key((string) $row[2])][] = (string) $row[0];
        }
    }

    /** One shared instance per process (the data file is ~4,000 rows). */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /** Lower-case, accents folded, punctuation to spaces. The same folding the build script applies. */
    public static function key(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i', 'i̇' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ş' => 's', 'ś' => 's', 'ž' => 'z', 'ý' => 'y', 'ß' => 'ss',
        ]);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /**
     * @return array{status:string,input:string,label?:string,city?:string,country?:string,codes?:string[],hint?:string,options?:array<int,array{label:string,city:string,country:string,codes:string[]}>}
     */
    public function resolve(string $input): array
    {
        $raw = trim($input);
        $k   = self::key($raw);
        if ($k === '') {
            return ['status' => 'unknown', 'input' => $raw];
        }

        // 1. An explicit airport code typed as a code ("EBB", "dxb" only when nothing else is called that).
        $asCode = strtoupper($k);
        $typedAsCode = (bool) preg_match('/^[A-Z]{3}$/', $raw);
        if ($typedAsCode && isset($this->byCode[$asCode])) {
            return $this->place([$asCode], $raw);
        }

        // 2. Everyday aliases.
        if (isset(self::ALIASES[$k])) {
            $target = self::ALIASES[$k];
            if (isset($this->byCode[$target])) {
                return $this->place([$target], $raw, self::ALIAS_DISPLAY[$k] ?? self::title($k));
            }
            $k = $target;
        }

        // 3. "City Country" — a trailing country narrows the match.
        $country = null;
        [$cityKey, $country] = $this->splitCountry($k);

        // 4. City name.
        $codes = $this->byCity[$cityKey] ?? [];
        if ($country !== null) {
            $codes = array_values(array_filter($codes, fn ($c) => $this->byCode[$c][3] === $country));
        }

        // 5. Airport name ("heathrow", "entebbe", "jomo kenyatta") when no city is called that.
        if (! $codes) {
            $codes = $this->byAirportName($cityKey, $country);
        }

        // 6. A country instead of a city ("Maldives", "Rwanda"). One main airport = that is the place;
        //    several = ask which city. A lower-case three-letter word is NOT read as an airport code:
        //    "goa" is a destination in India, and GOA is Genoa.
        if (! $codes) {
            $iso = $this->countryKeys[$k] ?? null;
            if ($iso !== null) {
                $large = array_values(array_filter(array_keys($this->byCode),
                    fn ($c) => $this->byCode[$c][3] === $iso && $this->byCode[$c][4] === 'L'));
                if (count($large) === 1) {
                    return $this->place($large, $raw, $this->countryName($iso));
                }

                return ['status' => 'unknown', 'input' => $raw, 'hint' => 'country', 'country' => $iso, 'label' => $this->countryName($iso)];
            }

            return ['status' => 'unknown', 'input' => $raw];
        }

        // Group by city + country. One group = one place.
        $groups = [];
        foreach ($codes as $c) {
            $row = $this->byCode[$c];
            $groups[self::key($row[2]) . '|' . $row[3]][] = $c;
        }
        if (count($groups) === 1) {
            return $this->place(array_values($groups)[0], $raw);
        }

        // Same name in several countries. One clearly dominant place wins; otherwise ask.
        $rank = [];
        foreach ($groups as $gk => $gc) {
            $rank[$gk] = max(array_map(fn ($c) => self::SIZE_RANK[$this->byCode[$c][4]] ?? 0, $gc));
        }
        arsort($rank);
        $top    = array_key_first($rank);
        $values = array_values($rank);
        if ($values[0] === 3 && ($values[1] ?? 0) < 3) {
            return $this->place($groups[$top], $raw);
        }

        $options = [];
        foreach (array_keys($rank) as $gk) {
            $p = $this->place($groups[$gk], $raw);
            $options[] = ['label' => $p['label'], 'city' => $p['city'], 'country' => $p['country'], 'codes' => $p['codes']];
            if (count($options) >= 4) {
                break;
            }
        }

        return ['status' => 'ambiguous', 'input' => $raw, 'options' => $options];
    }

    /** Human label for a stored place, e.g. "Dubai, United Arab Emirates". */
    public function countryName(string $iso): string
    {
        return (string) ($this->countries[$iso] ?? $iso);
    }

    public function airportName(string $code): string
    {
        return (string) ($this->byCode[strtoupper($code)][1] ?? '');
    }

    private static function title(string $k): string
    {
        return mb_convert_case($k, MB_CASE_TITLE, 'UTF-8');
    }

    /** @return array{0:string,1:?string} [city key, ISO country or null] */
    private function splitCountry(string $k): array
    {
        $words = explode(' ', $k);
        // Try the last one, two or three words as a country ("london uk", "birmingham united states").
        for ($n = min(3, count($words) - 1); $n >= 1; $n--) {
            $tail = implode(' ', array_slice($words, -$n));
            $head = implode(' ', array_slice($words, 0, -$n));
            $iso  = $this->countryKeys[$tail] ?? (strlen($tail) === 2 && isset($this->countries[strtoupper($tail)]) ? strtoupper($tail) : null);
            if ($iso !== null && $head !== '' && (isset($this->byCity[$head]) || isset(self::ALIASES[$head]))) {
                $head = isset(self::ALIASES[$head]) && ! isset($this->byCode[self::ALIASES[$head]]) ? self::ALIASES[$head] : $head;

                return [$head, $iso];
            }
        }

        return [$k, null];
    }

    /** @return string[] codes whose airport name contains every word typed, as whole words */
    private function byAirportName(string $k, ?string $country): array
    {
        $words = array_values(array_filter(explode(' ', $k), fn ($w) => ! in_array($w, ['airport', 'international', 'intl', 'the'], true)));
        if (! $words || (count($words) === 1 && strlen($words[0]) < 4)) {
            return [];
        }
        $hits = [];
        foreach ($this->byCode as $code => $row) {
            if ($country !== null && $row[3] !== $country) {
                continue;
            }
            $name = ' ' . self::key($row[1]) . ' ';
            $all  = true;
            foreach ($words as $w) {
                if (! str_contains($name, ' ' . $w . ' ')) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $hits[] = $code;
            }
        }

        return count($hits) <= 6 ? $hits : [];   // a word that matches dozens of airports names nothing
    }

    /**
     * @param  string[]  $codes
     * @return array{status:string,input:string,label:string,city:string,country:string,codes:string[]}
     */
    private function place(array $codes, string $raw, ?string $display = null): array
    {
        usort($codes, fn ($a, $b) => [self::SIZE_RANK[$this->byCode[$b][4]] ?? 0, $a] <=> [self::SIZE_RANK[$this->byCode[$a][4]] ?? 0, $b]);
        $first = $this->byCode[$codes[0]];
        $city  = (string) $first[2];
        // A single airport whose name is better known than its town ("Kilimanjaro" is in Arusha).
        $label = $city . ', ' . $this->countryName((string) $first[3]);
        if (count($codes) === 1) {
            $label = $city . ' (' . $codes[0] . '), ' . $this->countryName((string) $first[3]);
            // The customer named the airport and its town is called something else ("Kilimanjaro"
            // is in Arusha): show the name they used, so the summary reads as what they asked for.
            $typed = self::key($raw);
            if ($typed !== '' && strlen($typed) > 3 && ! str_contains(self::key($city), $typed)
                && str_contains(' ' . self::key((string) $first[1]) . ' ', ' ' . $typed . ' ')) {
                $label = (string) $first[1] . ' (' . $codes[0] . '), ' . $this->countryName((string) $first[3]);
            }
            // Reached through an everyday name or a country ("Kampala", "Goa", "Mauritius"): keep
            // the word the customer used instead of the airport's own town.
            if ($display !== null) {
                $country = $this->countryName((string) $first[3]);
                $label   = $display . ' (' . $codes[0] . ')' . (self::key($display) === self::key($country) ? '' : ', ' . $country);
            }
        }

        return [
            'status'  => 'ok',
            'input'   => $raw,
            'label'   => $label,
            'city'    => $city,
            'country' => (string) $first[3],
            'codes'   => array_values($codes),
        ];
    }
}
