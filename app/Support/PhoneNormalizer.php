<?php declare(strict_types=1);

namespace App\Support;

/**
 * Canonical phone form for the Kampala ↔ Juba corridor, so the same customer isn't split across
 * "0211…", "211…" and bare-local variants. Deliberately CONSERVATIVE: it fixes the common, safe
 * cases and refuses to guess on anything odd, so it can never merge two genuinely different numbers.
 *
 * Rules:
 *   1. Keep digits only, strip leading zeros (local trunk prefix).
 *   2. If it already starts with a known East-Africa country code and has a full subscriber number,
 *      keep it as-is.
 *   3. If it's a bare 9-digit local subscriber number, prepend the country code by its first digit
 *      (9… → South Sudan 211, 7… → Uganda 256 — the two mobile ranges on this route).
 *   4. Anything else (unusual length / unknown prefix) is left as the leading-zero-stripped digits —
 *      never force-prefixed, so a foreign or mistyped number is preserved rather than mangled.
 */
final class PhoneNormalizer
{
    /** Known East-Africa country codes we recognise as already-prefixed. */
    private const CCS = ['211', '256', '254', '255', '250', '243', '249', '257', '253', '291'];

    public static function canonical(?string $raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        $d = ltrim($d, '0');
        if ($d === '') {
            return '';
        }

        // Already carries a known country code + a plausible subscriber number → keep.
        foreach (self::CCS as $c) {
            if (str_starts_with($d, $c) && strlen($d) >= strlen($c) + 7) {
                return $d;
            }
        }

        // Bare 9-digit local mobile → prefix by range (SS 9x, UG 7x).
        if (strlen($d) === 9) {
            if ($d[0] === '9') return '211' . $d;
            if ($d[0] === '7') return '256' . $d;
        }

        // Unknown shape — preserve, don't guess.
        return $d;
    }

    /** True when two raw phones resolve to the same customer. */
    public static function same(?string $a, ?string $b): bool
    {
        $ca = self::canonical($a);
        return $ca !== '' && $ca === self::canonical($b);
    }
}
