<?php

namespace App\Services\Travel\Passport;

/**
 * PURE. Finds and checks the machine-readable zone (MRZ) of a passport or ID card in OCR text.
 *
 * The MRZ is the two or three lines of capital letters, digits and "<" at the bottom of a passport
 * photo page (ICAO 9303 TD3, 2 × 44), on the back of a national ID card (TD1, 3 × 30) or on some
 * older cards and visas (TD2, 2 × 36). It carries a check digit for the document number, the date
 * of birth, the expiry date and the whole line, so a misread digit is caught instead of stored.
 *
 * A reading is accepted only when ALL of those check digits agree. The NAME has no check digit in
 * any format, so the customer is shown the name and asked to correct it (the trip summary they
 * confirm lists it).
 *
 * OCR on a phone photo mixes up look-alike characters (O/0, I/1, S/5, B/8, Z/2, G/6) and often reads
 * the "<" filler as K or C. Fields that can only be digits (dates, check digits) or only letters
 * (country codes, sex, names) are corrected by position. The DOCUMENT NUMBER is never corrected: it
 * may hold letters and digits, and in practice only its own check digit protects it (the composite
 * check moves in step with it), so trying look-alike spellings until one passes would sometimes
 * produce a wrong number that looks verified. A number that fails its check is a failed reading.
 */
final class Mrz
{
    private const TO_DIGIT  = ['O' => '0', 'Q' => '0', 'D' => '0', 'U' => '0', 'I' => '1', 'L' => '1', 'T' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6', 'K' => '<', 'C' => '<'];
    private const TO_LETTER = ['0' => 'O', '1' => 'I', '2' => 'Z', '5' => 'S', '8' => 'B', '6' => 'G', '4' => 'A', '7' => 'T'];

    private const FORMATS = ['TD3' => [2, 44], 'TD2' => [2, 36], 'TD1' => [3, 30]];

    /**
     * Find one valid MRZ in OCR output.
     *
     * @param  string $today  Y-m-d, to decide the century of a two-digit birth year
     * @return array{format:string,document_type:string,issuing_country:string,surname:string,given_names:string,
     *               document_number:string,nationality:string,date_of_birth:string,sex:string,expiry_date:string}|null
     */
    public static function find(string $ocrText, string $today): ?array
    {
        $lines = self::candidateLines($ocrText);
        $n = count($lines);
        foreach (self::FORMATS as $format => [$rows, $len]) {
            for ($i = 0; $i + $rows <= $n; $i++) {
                $group = array_slice($lines, $i, $rows);
                foreach (self::fits($group, $len) as $fitted) {
                    $r = self::parse($format, $fitted, $today);
                    if ($r !== null) {
                        return $r;
                    }
                }
            }
        }

        return null;
    }

    /** ICAO 9303 check digit: weights 7, 3, 1; digits as themselves, A–Z as 10–35, "<" as 0. */
    public static function checkDigit(string $s): int
    {
        $w = [7, 3, 1];
        $sum = 0;
        $s = strtoupper($s);
        for ($i = 0, $l = strlen($s); $i < $l; $i++) {
            $c = $s[$i];
            $v = ctype_digit($c) ? (int) $c : (ctype_upper($c) ? ord($c) - 55 : 0);
            $sum += $v * $w[$i % 3];
        }

        return $sum % 10;
    }

    /** Whole years between a Y-m-d birth date and a Y-m-d day. */
    public static function age(string $dob, string $on): ?int
    {
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if (! $b || ! $d || $b > $d) {
            return null;
        }

        return (int) $b->diff($d)->y;
    }

    // ---------------------------------------------------------------------------------------------

    /** OCR lines that look like MRZ lines: long, almost only A–Z 0–9 <, with at least one filler. */
    private static function candidateLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', strtoupper($text)) ?: [] as $raw) {
            $line = strtr($raw, ['«' => '<<', '‹' => '<', '＜' => '<', '|' => '', '¢' => 'C']);
            $line = preg_replace('/\s+/', '', $line) ?? '';
            if (strlen($line) < 20) {
                continue;
            }
            $good = preg_match_all('/[A-Z0-9<]/', $line);
            if ($good < 0.9 * strlen($line) || ! preg_match('/<|KK|CC/', $line)) {
                continue;
            }
            $out[] = preg_replace('/[^A-Z0-9<]/', '', $line) ?? '';
        }

        return $out;
    }

    /**
     * Ways to bring a group of OCR lines to the exact width: as read, padded with fillers on the right
     * (OCR drops trailing "<"), or trimmed of up to three stray characters at either end.
     *
     * @return array<int,string[]>
     */
    private static function fits(array $group, int $len): array
    {
        $options = [];
        foreach ($group as $line) {
            $l = strlen($line);
            if ($l < $len - 15 || $l > $len + 3) {
                return [];
            }
            $opts = [];
            if ($l <= $len) {
                $opts[] = str_pad($line, $len, '<');
            } else {
                for ($cut = 0; $cut <= $l - $len; $cut++) {
                    $opts[] = substr($line, $cut, $len);
                }
            }
            $options[] = $opts;
        }
        $combos = [[]];
        foreach ($options as $opts) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($opts as $o) {
                    $next[] = array_merge($c, [$o]);
                }
            }
            $combos = $next;
        }

        return array_slice($combos, 0, 32);
    }

    private static function parse(string $format, array $l, string $today): ?array
    {
        if ($format === 'TD1') {
            [$a, $b, $c] = $l;
            $type = self::letters(substr($a, 0, 2));
            $issuing = self::letters(substr($a, 2, 3));
            $numRaw = substr($a, 5, 9);
            $numChk = self::digits(substr($a, 14, 1));
            $opt1 = substr($a, 15, 15);
            $dob = self::digits(substr($b, 0, 6));
            $dobChk = self::digits(substr($b, 6, 1));
            $sex = substr($b, 7, 1);
            $exp = self::digits(substr($b, 8, 6));
            $expChk = self::digits(substr($b, 14, 1));
            $nat = self::letters(substr($b, 15, 3));
            $opt2 = substr($b, 18, 11);
            $comp = self::digits(substr($b, 29, 1));
            $names = $c;
            // A document number longer than nine characters continues in the optional field, which
            // then ends with its check digit; the check digit position holds a filler.
            $long = $numChk === '<';
        } else {
            [$a, $b] = $l;
            $w = strlen($a);
            $type = self::letters(substr($a, 0, 2));
            $issuing = self::letters(substr($a, 2, 3));
            $names = substr($a, 5);
            $numRaw = substr($b, 0, 9);
            $numChk = self::digits(substr($b, 9, 1));
            $nat = self::letters(substr($b, 10, 3));
            $dob = self::digits(substr($b, 13, 6));
            $dobChk = self::digits(substr($b, 19, 1));
            $sex = substr($b, 20, 1);
            $exp = self::digits(substr($b, 21, 6));
            $expChk = self::digits(substr($b, 27, 1));
            $optional = substr($b, 28, $w - 29);
            $comp = self::digits(substr($b, $w - 1, 1));
            $long = false;
        }

        if (! preg_match('/^[PIACV]/', $type)) {
            return null;                                    // not a travel or identity document
        }
        if (! self::checks($dob, $dobChk) || ! self::checks($exp, $expChk)) {
            return null;
        }
        $dobIso = self::date($dob, $today, true);
        $expIso = self::date($exp, $today, false);
        if ($dobIso === null || $expIso === null) {
            return null;
        }

        // The document number, exactly as read.
        $num = $numRaw;
        if ($format === 'TD1') {
            if ($long) {
                // A number longer than nine characters continues in the optional field, which then
                // ends with the number's check digit.
                $tail = rtrim($opt1, '<');
                if ($tail === '' || ! ctype_digit(substr($tail, -1)) || ! self::checks($num . substr($tail, 0, -1), substr($tail, -1))) {
                    return null;
                }
                $full = $num . substr($tail, 0, -1);
            } else {
                if (! self::checks($num, $numChk)) {
                    return null;
                }
                $full = $num;
            }
            $compStr = $num . substr($a, 14, 16) . $dob . $dobChk . $exp . $expChk . $opt2;
        } else {
            if (! self::checks($num, $numChk)) {
                return null;
            }
            $full = $num;
            $compStr = $num . $numChk . $dob . $dobChk . $exp . $expChk . $optional;
        }
        if (! self::checks($compStr, $comp)) {
            return null;
        }
        $number = rtrim($full, '<');

        [$surname, $given] = self::names($names);
        if ($surname === '') {
            return null;
        }
        $sexOut = match (self::letters($sex)) { 'M' => 'M', 'F' => 'F', default => 'X' };

        return [
            'format'          => $format,
            'document_type'   => rtrim($type, '<'),
            'issuing_country' => rtrim($issuing, '<'),
            'surname'         => $surname,
            'given_names'     => $given,
            'document_number' => $number,
            'nationality'     => rtrim($nat, '<'),
            'date_of_birth'   => $dobIso,
            'sex'             => $sexOut,
            'expiry_date'     => $expIso,
        ];
    }

    private static function checks(string $field, string $check): bool
    {
        $check = $check === '<' ? '0' : $check;

        return ctype_digit($check) && self::checkDigit($field) === (int) $check;
    }

    private static function digits(string $s): string
    {
        return strtr($s, self::TO_DIGIT);
    }

    private static function letters(string $s): string
    {
        return strtr($s, self::TO_LETTER);
    }

    private static function date(string $yymmdd, string $today, bool $birth): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }
        $yy = (int) substr($yymmdd, 0, 2);
        $nowYy = (int) substr($today, 2, 2);
        $year = $birth ? ($yy > $nowYy ? 1900 + $yy : 2000 + $yy) : 2000 + $yy;
        $iso = sprintf('%04d-%s-%s', $year, substr($yymmdd, 2, 2), substr($yymmdd, 4, 2));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        return ($d && $d->format('Y-m-d') === $iso) ? $iso : null;
    }

    /** "ERIKSSON<<ANNA<MARIA<<<<" → ["ERIKSSON", "ANNA MARIA"]. Fillers misread as K or C at the end are dropped. */
    private static function names(string $field): array
    {
        $f = strtr($field, self::TO_LETTER);
        // A trailing run that is mostly fillers is filler, even where OCR read some "<" as K or C.
        $f = preg_replace_callback('/[<KC]{3,}$/', fn ($m) => substr_count($m[0], '<') * 2 >= strlen($m[0]) ? '' : $m[0], $f) ?? $f;
        $f = rtrim($f, '<');
        $parts = explode('<<', $f, 2);
        $clean = fn (string $s) => trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $s)) ?? '');

        return [$clean($parts[0]), $clean($parts[1] ?? '')];
    }
}
