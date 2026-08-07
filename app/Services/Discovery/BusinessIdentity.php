<?php declare(strict_types=1);

namespace App\Services\Discovery;

/**
 * v4.59 — who a discovered business IS.
 *
 * PURE. The decision ADR-016 made, and the one everything downstream rests on: get it wrong and the
 * same supermarket arrives three times from three providers, or two different shops in Juba and
 * Kampala collapse into one record.
 *
 * The rule, in order:
 *
 *   1. A NORMALISED PHONE, when there is one. Customer identity in this system already is the phone
 *      number — 136 files reference `customer_phone` and twelve match on it — so a discovered
 *      business with a phone slots straight into machinery that already works.
 *
 *   2. Otherwise NAME + CITY, hashed. Common for a discovered business: a directory listing with an
 *      address and no number. The same mechanism `Lead.dedupe_key` already uses.
 *
 * A record identified the second way cannot be invoiced, messaged, or put on a statement, because
 * every one of those paths is phone-keyed. That is correct rather than a limitation: you cannot bill
 * someone you have no way to contact. Getting the number is the rep's job, and is the moment the
 * record becomes fully operational.
 */
final class BusinessIdentity
{
    /** Shorter than this and a "phone number" is a typo, not a number. */
    private const MIN_DIGITS = 7;

    /**
     * The dedupe key for a business, or '' when there is not enough to identify one at all.
     *
     * @return string  'p:256701234567' or 'n:<sha1>' or ''
     */
    public static function key(?string $phone, ?string $name, ?string $city): string
    {
        $digits = self::digits($phone);
        if ($digits !== '') {
            return 'p:' . $digits;
        }

        $n = self::norm($name);
        if ($n === '') {
            return '';                       // no phone and no name — nothing to be the same as
        }
        $c = self::norm($city);

        return 'n:' . sha1($n . '|' . $c);
    }

    /** True when this key came from a phone, and so can enter the phone-keyed flows. */
    public static function isReachable(string $key): bool
    {
        return str_starts_with($key, 'p:');
    }

    /**
     * A phone reduced to digits, or '' if it cannot be one.
     *
     * Leading zeros and country codes are left exactly as found. Normalising "0701…" to "256701…"
     * needs to know the country, and guessing wrong merges two different businesses — a far worse
     * outcome than the same shop appearing twice, which a human at the review queue can fix in a
     * second. Same-number-different-format is a real limitation, written down rather than papered
     * over with a guess.
     */
    public static function digits(?string $phone): string
    {
        $d = preg_replace('/[^0-9]/', '', (string) $phone);

        return strlen((string) $d) >= self::MIN_DIGITS ? (string) $d : '';
    }

    /** Lowercase, punctuation out, whitespace collapsed — so "ABC Supermarket" meets "abc supermarket". */
    public static function norm(?string $s): string
    {
        $x = mb_strtolower(trim((string) $s));
        $x = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $x);

        return trim((string) preg_replace('/\s+/u', ' ', $x));
    }

    /**
     * Merge findings for one business into a single set of best values.
     *
     * Highest confidence wins a field. A tie goes to the finding that arrived FIRST, because the
     * registry runs providers in the order the shop configured them and that order is a preference —
     * a shop that puts its own CSV before Google means it.
     *
     * @param  array<int,\App\Services\Discovery\Dto\BusinessFinding>  $findings
     * @return array<string,array{value:string, confidence:float, source:string, note:string}>
     */
    public static function best(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            if (! $f instanceof \App\Services\Discovery\Dto\BusinessFinding || ! $f->isUsable()) {
                continue;
            }
            $cur = $out[$f->field] ?? null;
            if ($cur === null || $f->confidence > $cur['confidence']) {
                $out[$f->field] = [
                    'value' => $f->value, 'confidence' => round($f->confidence, 3),
                    'source' => $f->source, 'note' => $f->note,
                ];
            }
        }

        return $out;
    }

    /** Every distinct source that contributed to a merged set — shown at the review queue. */
    public static function sources(array $best): array
    {
        $s = [];
        foreach ($best as $f) {
            $k = (string) ($f['source'] ?? '');
            if ($k !== '') {
                $s[$k] = true;
            }
        }

        return array_keys($s);
    }
}
