<?php

namespace App\Services\Travel\Enquiry;

/**
 * Everything about a travel enquiry that is not a flight segment: which services the customer wants,
 * where they want to go, roughly when, children's ages, purpose, budget, accommodation and how to
 * reach them. PURE — no database, no framework.
 *
 * It lives under the `brief` key of the EnquirySlots state, so a flight-only enquiry is unchanged.
 * The AI model reports what the customer said; THIS class decides what is stored. It never invents a
 * value, never prices anything and never drops a detail the customer did not change.
 *
 *   services      string[]  flights | hotel | safari | tour | transfer | car_hire | visa | insurance |
 *                           courier | ticket_change | other
 *   destinations  string[]  places in the order the customer wants to visit them (free text: a park,
 *                           a town or a country is as valid as a city with an airport)
 *   departure     where the trip starts when no flight is involved
 *   start_date, end_date  'Y-m-d' | null     date_text  "July 2027", "Easter week" when not exact
 *   nights        int | null
 *   child_ages    int[]     0–17
 *   purpose, accommodation   short free text
 *   budget        {amount:int, currency:?string, per:?string}  | null   (what the CUSTOMER said they
 *                 can spend — never a price from us)
 *   contact       {name, phone, email}
 */
final class EnquiryBrief
{
    public const MAX_DESTINATIONS = 8;

    public const SERVICES = [
        'flights' => 'Flights', 'hotel' => 'Hotel', 'safari' => 'Safari', 'tour' => 'Tour or package',
        'transfer' => 'Airport transfer', 'car_hire' => 'Car hire', 'visa' => 'Visa assistance',
        'insurance' => 'Travel insurance', 'courier' => 'Courier', 'ticket_change' => 'Change to an existing ticket',
        'other' => 'Other request',
    ];

    /** Services that are a request for help, not a trip: no destination, dates or party are needed. */
    public const NOT_A_TRIP = ['courier', 'ticket_change', 'other'];

    public const CURRENCIES = ['USD', 'UGX', 'KES', 'TZS', 'RWF', 'SSP', 'EUR', 'GBP', 'AED', 'ZAR', 'INR', 'CNY', 'CAD', 'AUD', 'CHF', 'SAR', 'QAR', 'ETB', 'NGN'];

    private const SERVICE_WORDS = [
        'flights' => ['flight', 'flights', 'air ticket', 'air tickets', 'air ticketing', 'ticket', 'tickets', 'airfare', 'plane ticket'],
        'hotel' => ['hotel', 'hotels', 'hotel booking', 'accommodation', 'lodge', 'lodging', 'resort', 'apartment'],
        'safari' => ['safari', 'safaris', 'game drive', 'gorilla trekking', 'gorilla tracking', 'chimp trekking', 'wildlife'],
        'tour' => ['tour', 'tours', 'package', 'holiday package', 'holiday', 'vacation', 'honeymoon package', 'attraction', 'attractions', 'excursion', 'day trip', 'cruise'],
        'transfer' => ['transfer', 'transfers', 'airport transfer', 'airport pickup', 'pickup', 'pick up', 'drop off', 'shuttle'],
        'car_hire' => ['car hire', 'car rental', 'rent a car', 'vehicle hire', 'self drive', 'driver'],
        'visa' => ['visa', 'visas', 'visa processing', 'visa assistance', 'e-visa', 'evisa'],
        'insurance' => ['insurance', 'travel insurance', 'cover'],
        'courier' => ['courier', 'parcel', 'cargo', 'shipping'],
        'ticket_change' => ['ticket change', 'change ticket', 'reschedule', 'rebooking', 'rebook', 're-adjustment', 'readjustment', 'refund', 'cancel ticket', 'date change'],
        'other' => ['other'],
    ];

    private const CLEARABLE = ['departure', 'end_date', 'date_text', 'nights', 'child_ages', 'purpose', 'budget', 'accommodation', 'destinations'];

    public static function blank(): array
    {
        return [
            'services' => [], 'destinations' => [], 'departure' => null,
            'start_date' => null, 'end_date' => null, 'date_text' => null, 'nights' => null,
            'child_ages' => [], 'purpose' => null, 'accommodation' => null, 'budget' => null,
            'contact' => ['name' => null, 'phone' => null, 'email' => null],
        ];
    }

    /** A stored value (possibly null or from an older row) as a full brief. */
    public static function normalise(mixed $brief): array
    {
        $b = array_merge(self::blank(), is_array($brief) ? array_intersect_key($brief, self::blank()) : []);
        $b['contact'] = array_merge(self::blank()['contact'], is_array($b['contact']) ? array_intersect_key($b['contact'], self::blank()['contact']) : []);
        foreach (['services', 'destinations', 'child_ages'] as $k) {
            $b[$k] = is_array($b[$k]) ? array_values($b[$k]) : [];
        }
        // A fixed key order: PostgreSQL's jsonb returns object keys in its own order, and two briefs
        // with the same content must compare (and hash) as the same brief.
        $b['budget'] = is_array($b['budget']) && isset($b['budget']['amount'])
            ? ['amount' => (int) $b['budget']['amount'], 'currency' => $b['budget']['currency'] ?? null, 'per' => $b['budget']['per'] ?? null]
            : null;

        return $b;
    }

    /**
     * Merge what the customer just said into the brief.
     *
     * @param  array    $state    the whole EnquirySlots state (by reference: passengers may be derived from ages)
     * @param  string[] $changed  field names changed so far (appended to)
     * @param  callable(string,string,string):void $issue  records a refused value: code, field, detail
     */
    public static function apply(array &$state, array $patch, string $today, array &$changed, callable $issue): void
    {
        $b   = self::normalise($state['brief'] ?? null);
        $was = $b;

        foreach ((array) ($patch['clear'] ?? []) as $f) {
            $f = is_string($f) ? strtolower(trim($f)) : '';
            if (in_array($f, self::CLEARABLE, true)) {
                $b[$f] = self::blank()[$f];
            }
        }

        // --- services: what is mentioned is ADDED; removal is explicit ----------------------------
        foreach (self::listOf($patch['services'] ?? null) as $name) {
            $s = self::service($name);
            if ($s !== null && ! in_array($s, $b['services'], true)) {
                $b['services'][] = $s;
            }
        }
        foreach (self::listOf($patch['remove_services'] ?? null) as $name) {
            $s = self::service($name);
            $b['services'] = array_values(array_filter($b['services'], fn ($x) => $x !== $s));
        }

        // --- destinations: the FULL ordered list after the change ---------------------------------
        if (array_key_exists('destinations', $patch)) {
            $list = [];
            foreach (self::listOf($patch['destinations']) as $name) {
                $name = self::text($name, 60);
                if ($name !== null && ! in_array(mb_strtolower($name), array_map('mb_strtolower', $list), true)) {
                    $list[] = $name;
                }
            }
            if ($list) {
                if (count($list) > self::MAX_DESTINATIONS) {
                    $issue('too_many_destinations', 'destinations', (string) count($list));
                    $list = array_slice($list, 0, self::MAX_DESTINATIONS);
                }
                $b['destinations'] = $list;
            }
        }

        foreach (['departure' => 60, 'date_text' => 60, 'purpose' => 60, 'accommodation' => 120] as $f => $max) {
            if (array_key_exists($f, $patch)) {
                $v = self::text($patch[$f], $max);
                if ($v !== null) {
                    $b[$f] = $v;
                }
            }
        }

        // --- dates for a trip with no flight ------------------------------------------------------
        foreach (['start_date', 'end_date'] as $f) {
            if (self::str($patch[$f] ?? null) !== '') {
                $d = self::date(self::str($patch[$f]), $today, $f, $issue);
                if ($d !== null) {
                    $b[$f] = $d;
                    if ($f === 'start_date' && ! array_key_exists('date_text', $patch)) {
                        $b['date_text'] = null;            // an exact date replaces "sometime in July"
                    }
                }
            }
        }
        if ($b['start_date'] !== null && $b['end_date'] !== null && $b['end_date'] < $b['start_date']) {
            $issue('end_before_start', 'end_date', $b['end_date']);
            $b['end_date'] = null;                          // ask, do not guess
        }
        if (array_key_exists('nights', $patch) && is_numeric($patch['nights'])) {
            $n = (int) $patch['nights'];
            $n >= 1 && $n <= 90 ? $b['nights'] = $n : $issue('bad_nights', 'nights', (string) $n);
        }

        // --- children's ages ----------------------------------------------------------------------
        if (array_key_exists('child_ages', $patch) && is_array($patch['child_ages'])) {
            $ages = [];
            foreach (array_slice($patch['child_ages'], 0, 12) as $a) {
                if (is_numeric($a) && (int) $a >= 0 && (int) $a <= 17) {
                    $ages[] = (int) $a;
                } else {
                    $issue('bad_child_age', 'child_ages', self::str($a));
                }
            }
            if ($ages) {
                $b['child_ages'] = $ages;
                // Ages state the party exactly; fill the counts only when the customer never gave them.
                if ($state['children'] === null && $state['infants'] === null && ! array_key_exists('children', $patch) && ! array_key_exists('infants', $patch)) {
                    $state['children'] = count(array_filter($ages, fn ($a) => $a >= 2));
                    $state['infants']  = count(array_filter($ages, fn ($a) => $a < 2));
                    $changed[] = 'children';
                }
            }
        }
        $young = (int) ($state['children'] ?? 0) + (int) ($state['infants'] ?? 0);
        if ($b['child_ages'] && ($state['children'] !== null || $state['infants'] !== null) && count($b['child_ages']) > $young) {
            $issue('child_ages_mismatch', 'child_ages', count($b['child_ages']) . ' ages for ' . $young);
            $b['child_ages'] = $was['child_ages'];
        }

        // --- budget: what the customer says they can spend ----------------------------------------
        $budget = $b['budget'];
        if (array_key_exists('budget', $patch) && is_numeric($patch['budget'])) {
            $amount = (int) round((float) $patch['budget']);
            if ($amount >= 1 && $amount <= 1000000000) {
                $budget = ['amount' => $amount, 'currency' => $budget['currency'] ?? null, 'per' => $budget['per'] ?? null];
            } else {
                $issue('bad_budget', 'budget', self::str($patch['budget']));
            }
        }
        if (array_key_exists('budget_currency', $patch) && self::str($patch['budget_currency']) !== '') {
            $cur = strtoupper(self::str($patch['budget_currency']));
            $cur = ['$' => 'USD', 'US$' => 'USD', 'DOLLARS' => 'USD', 'DOLLAR' => 'USD', 'SHILLINGS' => null, 'EURO' => 'EUR', 'EUROS' => 'EUR', 'POUNDS' => 'GBP', '€' => 'EUR', '£' => 'GBP'][$cur] ?? $cur;
            if ($cur !== null && in_array($cur, self::CURRENCIES, true)) {
                if ($budget !== null) {
                    $budget['currency'] = $cur;
                }
            } else {
                $issue('bad_currency', 'budget', self::str($patch['budget_currency']));
            }
        }
        if (array_key_exists('budget_per', $patch) && $budget !== null) {
            $per = strtolower(self::str($patch['budget_per']));
            $per = ['person' => 'person', 'per person' => 'person', 'pp' => 'person', 'each' => 'person', 'total' => 'total', 'group' => 'total', 'all' => 'total', 'family' => 'total'][$per] ?? null;
            if ($per !== null) {
                $budget['per'] = $per;
            }
        }
        $b['budget'] = $budget;

        // --- contact ------------------------------------------------------------------------------
        if (array_key_exists('contact_name', $patch) && ($v = self::text($patch['contact_name'], 80)) !== null) {
            $b['contact']['name'] = $v;
        }
        if (array_key_exists('contact_phone', $patch) && self::str($patch['contact_phone']) !== '') {
            $digits = preg_replace('/[^0-9]/', '', self::str($patch['contact_phone'])) ?? '';
            strlen($digits) >= 7 && strlen($digits) <= 15 ? $b['contact']['phone'] = $digits : $issue('bad_phone', 'contact', self::str($patch['contact_phone']));
        }
        if (array_key_exists('contact_email', $patch) && self::str($patch['contact_email']) !== '') {
            $email = mb_strtolower(self::str($patch['contact_email']));
            filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 120 ? $b['contact']['email'] = $email : $issue('bad_email', 'contact', mb_substr($email, 0, 40));
        }

        foreach ($b as $k => $v) {
            if ($v !== $was[$k]) {
                $changed[] = $k;
            }
        }
        $state['brief'] = $b;
    }

    /** The services the enquiry will hold once this patch is applied (nothing is stored). */
    public static function previewServices(array $state, array $patch): array
    {
        $services = self::normalise($state['brief'] ?? null)['services'];
        foreach (self::listOf($patch['services'] ?? null) as $name) {
            $s = self::service($name);
            if ($s !== null && ! in_array($s, $services, true)) {
                $services[] = $s;
            }
        }
        foreach (self::listOf($patch['remove_services'] ?? null) as $name) {
            $services = array_values(array_filter($services, fn ($x) => $x !== self::service($name)));
        }

        return $services;
    }

    /** Whether this enquiry involves a flight (so the flight questions apply). */
    public static function wantsFlight(array $state): bool
    {
        $b = self::normalise($state['brief'] ?? null);

        return in_array('flights', $b['services'], true)
            || ($b['services'] === [] && (($state['trip_type'] ?? null) !== null || ($state['segments'] ?? []) !== []));
    }

    /** Only a request for help (courier, ticket change, other): not a trip at all. */
    public static function isRequestOnly(array $state): bool
    {
        $b = self::normalise($state['brief'] ?? null);

        return $b['services'] !== [] && array_diff($b['services'], self::NOT_A_TRIP) === [];
    }

    /**
     * What must still be asked when the enquiry does NOT involve a flight, in asking order.
     *
     * @return string[]
     */
    public static function missing(array $state): array
    {
        $b = self::normalise($state['brief'] ?? null);
        if (self::isRequestOnly($state)) {
            return ($state['notes'] ?? null) === null ? ['request_details'] : [];
        }
        $out = [];
        if ($b['services'] === [] && $b['destinations'] === []) {
            $out[] = 'service';
        }
        if ($b['destinations'] === []) {
            $out[] = 'destinations';
        }
        if ($b['start_date'] === null && $b['date_text'] === null) {
            $out[] = 'travel_dates';
        }
        if (($state['adults'] ?? null) === null) {
            $out[] = 'passengers';
        }

        return $out;
    }

    /** Ages are needed for every child, whatever the service. */
    public static function missingChildAges(array $state): bool
    {
        $b = self::normalise($state['brief'] ?? null);

        return (int) ($state['children'] ?? 0) > 0 && count($b['child_ages']) < (int) $state['children'];
    }

    /** Optional things worth one question, never a blocker. */
    public static function softQuestions(array $state): array
    {
        $b = self::normalise($state['brief'] ?? null);
        $out = [];
        if ($b['budget'] !== null && $b['budget']['currency'] === null) {
            $out[] = 'budget_currency';
        }

        return $out;
    }

    public static function servicesLine(array $brief): string
    {
        return implode(', ', array_map(fn ($s) => self::SERVICES[$s] ?? $s, $brief['services'] ?? []));
    }

    public static function budgetLine(?array $budget): string
    {
        if (! $budget || empty($budget['amount'])) {
            return '';
        }

        return trim(($budget['currency'] ?? '') . ' ' . number_format((int) $budget['amount']))
            . (($budget['per'] ?? null) === 'person' ? ' per person' : (($budget['per'] ?? null) === 'total' ? ' in total' : ''))
            . (($budget['currency'] ?? null) === null ? ' (currency not stated)' : '');
    }

    /**
     * The brief as short lines for the customer summary, the staff alert and the prompt.
     *
     * @return string[]
     */
    public static function summaryLines(array $state): array
    {
        $b = self::normalise($state['brief'] ?? null);
        $lines = [];
        if ($b['services']) {
            $lines[] = 'Services: ' . self::servicesLine($b);
        }
        if ($b['destinations']) {
            $lines[] = (count($b['destinations']) > 1 ? 'Destinations: ' : 'Destination: ') . implode(' → ', $b['destinations']);
        }
        if ($b['departure']) {
            $lines[] = 'Starting from: ' . $b['departure'];
        }
        $when = '';
        if ($b['start_date']) {
            $when = EnquirySlots::niceDate($b['start_date']) . ($b['end_date'] ? ' to ' . EnquirySlots::niceDate($b['end_date']) : '');
        } elseif ($b['date_text']) {
            $when = $b['date_text'] . ' (dates not fixed)';
        }
        if ($b['nights']) {
            $when .= ($when !== '' ? ' · ' : '') . $b['nights'] . ' night' . ($b['nights'] === 1 ? '' : 's');
        }
        if ($when !== '') {
            $lines[] = 'When: ' . $when;
        }
        if ($b['child_ages']) {
            $lines[] = 'Children\'s ages: ' . implode(', ', $b['child_ages']);
        }
        if ($b['purpose']) {
            $lines[] = 'Purpose: ' . $b['purpose'];
        }
        if ($b['accommodation']) {
            $lines[] = 'Accommodation: ' . $b['accommodation'];
        }
        if (($bl = self::budgetLine($b['budget'])) !== '') {
            $lines[] = 'Customer\'s budget: ' . $bl;
        }

        return $lines;
    }

    /** Name, phone and email the customer gave, as one line (empty when none). */
    public static function contactLine(array $state): string
    {
        $c = self::normalise($state['brief'] ?? null)['contact'];

        return implode(' · ', array_filter([$c['name'], $c['phone'] ? '+' . $c['phone'] : null, $c['email']]));
    }

    // ---------------------------------------------------------------------------------------------

    public static function service(mixed $name): ?string
    {
        $n = mb_strtolower(trim(self::str($name)));
        if ($n === '') {
            return null;
        }
        $n = str_replace(['_', '-'], ' ', $n);
        if (isset(self::SERVICES[str_replace(' ', '_', $n)])) {
            return str_replace(' ', '_', $n);
        }
        foreach (self::SERVICE_WORDS as $key => $words) {
            if (in_array($n, $words, true)) {
                return $key;
            }
        }
        foreach (self::SERVICE_WORDS as $key => $words) {
            foreach ($words as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $n)) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /** @return array<int,mixed> */
    private static function listOf(mixed $v): array
    {
        if (is_array($v)) {
            return array_values($v);
        }
        if (is_string($v) && trim($v) !== '') {
            return preg_split('/\s*[,;]\s*/', trim($v)) ?: [];
        }

        return [];
    }

    private static function date(string $v, string $today, string $field, callable $issue): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $issue('bad_date', $field, mb_substr($v, 0, 20));

            return null;
        }
        if ($v < $today) {
            $issue('date_past', $field, $v);

            return null;
        }
        // Holidays are planned further ahead than flights can be ticketed: allow two years.
        if ($v > date('Y-m-d', strtotime($today . ' +730 days'))) {
            $issue('date_far', $field, $v);

            return null;
        }

        return $v;
    }

    private static function str(mixed $v): string
    {
        return is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';
    }

    private static function text(mixed $v, int $max): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
