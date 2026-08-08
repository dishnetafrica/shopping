<?php declare(strict_types=1);

namespace App\Services\Discovery;

/**
 * v4.62 — what a discovered business may be allowed to write, and what it may not.
 *
 * PURE. Findings and a current record in, a list of safe writes out. No models, no database.
 *
 * ADR-016 decision 4a, and the one rule the whole review queue rests on:
 *
 *   **A suggestion is only ever applied to a field that is still empty.**
 *
 * A rep who typed the real phone number on Tuesday must not have it replaced by Google on
 * Wednesday. Bulk approve exists; bulk overwrite does not. Written here as a pure function so the
 * rule can be tested without a database and cannot be quietly reimplemented at a second call site
 * with one condition missing.
 */
final class BusinessMatch
{
    /**
     * Finding field → the column it is allowed to fill.
     *
     * A whitelist, not a convention: a provider inventing a field name must not be able to write to
     * a column merely by guessing what it is called. Two things worth noticing —
     *
     *   `category` → `segment`, because the CRM has always called it segment and renaming a column
     *   136 files reference is not a thing to do for tidiness.
     *
     *   `external_id` is absent on purpose. It belongs in the `external_ids` json keyed by which
     *   provider said it, and a scalar column write would lose that.
     */
    public const COLUMN = [
        'name'           => 'name',
        'phone'          => 'phone',
        'alt_phone'      => 'alt_phone',
        'email'          => 'email',
        'website'        => 'website',
        'address'        => 'address',
        'city'           => 'city',
        'country'        => 'country',
        'category'       => 'segment',
        'contact_person' => 'contact_person',
        'latitude'       => 'latitude',
        'longitude'      => 'longitude',
        'size'           => 'size',
    ];

    /** ADR-016 amendment 2: a band a rep can observe, never a headcount nobody knows. */
    public const BANDS = ['unknown', 'small', 'medium', 'large', 'enterprise'];

    /**
     * The writes that are safe right now.
     *
     * @param  array<string,array{value:string,confidence:float,source:string}>  $best   BusinessIdentity::best()
     * @param  array<string,mixed>  $current  the profile as it stands, keyed by column
     * @return array<string,string>  column => value
     */
    public static function writable(array $best, array $current): array
    {
        $out = [];
        foreach (self::COLUMN as $field => $column) {
            if (! isset($best[$field])) {
                continue;
            }
            $value = trim((string) ($best[$field]['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($field === 'size') {
                $value = self::band($value);
                if ($value === '' || $value === 'unknown') {
                    continue;            // "unknown" is not a fact; it is the absence of one
                }
            }
            // The rule. `null` and '' are empty; anything a person has typed is not, including a
            // value they typed and later disagreed with.
            if (! self::isEmpty($current[$column] ?? null)) {
                continue;
            }
            $out[$column] = $value;
        }

        return $out;
    }

    /**
     * Is a stored value empty enough to fill in?
     *
     * Whitespace counts as empty; a literal zero does not. A latitude of 0 is a real place off the
     * coast of Ghana, and a credit limit of 0 is a decision somebody made — overwriting either
     * because it looks falsy is the exact failure this class exists to prevent.
     */
    public static function isEmpty($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * Free text to the closed size vocabulary, or '' when it is not one of them.
     *
     * A spreadsheet column headed "size" will contain anything at all. Letting it through as typed
     * would put "biggish" and "2 branches" in a field the rest of the system expects to filter on.
     */
    public static function band(?string $raw): string
    {
        $s = strtolower(trim((string) $raw));
        if ($s === '') {
            return '';
        }
        if (in_array($s, self::BANDS, true)) {
            return $s;
        }

        return match (true) {
            in_array($s, ['xs', 's', 'sm', 'tiny', 'micro', 'kiosk', 'stall'], true)   => 'small',
            in_array($s, ['m', 'md', 'mid', 'medium sized', 'average'], true)          => 'medium',
            in_array($s, ['l', 'lg', 'big'], true)                                     => 'large',
            in_array($s, ['xl', 'xxl', 'chain', 'corporate', 'group'], true)           => 'enterprise',
            default                                                                    => '',
        };
    }

    /**
     * Is this row worth a person's attention?
     *
     * Two bars. It must have a name — nobody can review a phone number floating in space — and it
     * must be able to TELL the shop something. A discovered business whose every field is already
     * filled in is not a discovery; putting it in the queue would train a reviewer to approve
     * without reading, which is how the one rule above stops protecting anybody.
     *
     * @param  array<string,string>  $writable  the output of writable()
     */
    public static function isWorthReviewing(array $best, array $writable): bool
    {
        $name = trim((string) ($best['name']['value'] ?? ''));

        return $name !== '' && $writable !== [];
    }

    /**
     * What the screen shows: every proposed write, with where it came from.
     *
     * @return array<int,array{column:string, field:string, value:string, confidence:float, source:string}>
     */
    public static function preview(array $best, array $writable): array
    {
        $byColumn = [];
        foreach (self::COLUMN as $field => $column) {
            $byColumn[$column] = $field;
        }

        $out = [];
        foreach ($writable as $column => $value) {
            $field = $byColumn[$column] ?? $column;
            $out[] = [
                'column'     => $column,
                'field'      => $field,
                'value'      => $value,
                'confidence' => round((float) ($best[$field]['confidence'] ?? 0), 3),
                'source'     => (string) ($best[$field]['source'] ?? ''),
            ];
        }

        return $out;
    }

    /** The best claim on the row, used only to sort the queue so the surest work comes first. */
    public static function topConfidence(array $best): float
    {
        $top = 0.0;
        foreach ($best as $f) {
            $c = (float) ($f['confidence'] ?? 0);
            if ($c > $top) {
                $top = $c;
            }
        }

        return round(min(1.0, max(0.0, $top)), 3);
    }
}
