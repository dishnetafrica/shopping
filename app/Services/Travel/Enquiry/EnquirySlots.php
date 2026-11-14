<?php

namespace App\Services\Travel\Enquiry;

/**
 * The trip a customer is describing, as data: what is known, what changed, what is still missing.
 * PURE — no database, no framework. The AI model reports what the customer said; THIS class decides
 * what is stored. It never invents a value and never drops one the customer did not change.
 *
 * State shape (all keys always present):
 *   trip_type      null | one_way | return | multi_city
 *   segments       [ {from: place|null, to: place|null, date: 'Y-m-d'|null}, … ]
 *   return_date    'Y-m-d' | null            (return trips only)
 *   adults, children, infants                int | null   (null = not stated yet)
 *   cabin          null | economy | premium_economy | business | first
 *   airlines       string[]
 *   baggage, notes string | null
 *   flexible_days  int | null
 *   direct_only    bool | null
 *   pending        open questions the validator raised, e.g. an ambiguous city
 *   brief          everything that is not a flight: services wanted, destinations, approximate
 *                  dates, children's ages, purpose, budget, accommodation, contact (EnquiryBrief)
 *
 * A place is {label, city, country, codes[], input} as returned by AirportDirectory.
 */
final class EnquirySlots
{
    public const MAX_SEGMENTS   = 6;
    public const MAX_DAYS_AHEAD = 365;
    /** Above this many seats a booking is a group request and goes to a person. */
    public const GROUP_FROM = 10;

    private const TRIP_TYPES = [
        'one_way' => 'one_way', 'oneway' => 'one_way', 'one-way' => 'one_way', 'single' => 'one_way',
        'return' => 'return', 'round' => 'return', 'round_trip' => 'return', 'roundtrip' => 'return', 'round-trip' => 'return',
        'multi_city' => 'multi_city', 'multicity' => 'multi_city', 'multi-city' => 'multi_city', 'multi' => 'multi_city',
    ];

    private const CABINS = [
        'economy' => 'economy', 'eco' => 'economy', 'coach' => 'economy', 'standard' => 'economy',
        'premium_economy' => 'premium_economy', 'premium economy' => 'premium_economy', 'premium' => 'premium_economy',
        'business' => 'business', 'biz' => 'business', 'business class' => 'business',
        'first' => 'first', 'first class' => 'first',
    ];

    private const CLEARABLE = ['return_date', 'cabin', 'airlines', 'baggage', 'flexible_days', 'direct_only', 'notes', 'children', 'infants'];

    public static function blank(): array
    {
        return [
            'trip_type' => null, 'segments' => [], 'return_date' => null,
            'adults' => null, 'children' => null, 'infants' => null,
            'cabin' => null, 'airlines' => [], 'baggage' => null,
            'flexible_days' => null, 'direct_only' => null, 'notes' => null,
            'pending' => [],
            'brief' => EnquiryBrief::blank(),
            // Names and ages read from the travellers' documents (TravelEnquiry::state()); never set by a patch.
            'travellers' => [],
        ];
    }

    /**
     * Merge what the customer just said into the stored trip.
     *
     * @param  array  $state    current state (blank() shape)
     * @param  array  $patch    fields the model reported as new or changed
     * @param  string $today    'Y-m-d' in the agency's timezone
     * @param  callable(string):array $resolvePlace  AirportDirectory::resolve
     * @return array{state:array,changed:string[],issues:array<int,array{code:string,field:string,detail:string}>}
     */
    public static function apply(array $state, array $patch, string $today, callable $resolvePlace): array
    {
        $state   = array_merge(self::blank(), $state);
        $changed = [];
        $issues  = [];
        $issue   = function (string $code, string $field, string $detail = '') use (&$issues) {
            $issues[] = ['code' => $code, 'field' => $field, 'detail' => $detail];
        };

        // --- a trip with no flight: its dates are the trip's dates, not a departure ---------------
        // The model may report "depart_date" for a safari or a hotel stay. Without a flight there is
        // no flight segment to hang it on, so it is stored as the trip's start (and end) date.
        $state['brief'] = EnquiryBrief::normalise($state['brief'] ?? null);
        $services = EnquiryBrief::previewServices($state, $patch);
        $flightWords = array_intersect_key($patch, ['from' => 1, 'to' => 1, 'segments' => 1, 'segment' => 1, 'trip_type' => 1, 'cabin' => 1, 'airlines' => 1]) !== [];
        if (! $flightWords && $services !== [] && ! in_array('flights', $services, true) && $state['segments'] === [] && $state['trip_type'] === null) {
            foreach (['depart_date' => 'start_date', 'return_date' => 'end_date'] as $flightKey => $tripKey) {
                if (array_key_exists($flightKey, $patch)) {
                    if (! array_key_exists($tripKey, $patch)) {
                        $patch[$tripKey] = $patch[$flightKey];
                    }
                    unset($patch[$flightKey]);
                }
            }
        }

        // --- clear --------------------------------------------------------------------------------
        foreach ((array) ($patch['clear'] ?? []) as $f) {
            $f = is_string($f) ? strtolower(trim($f)) : '';
            if (! in_array($f, self::CLEARABLE, true)) {
                continue;
            }
            $empty = $f === 'airlines' ? [] : null;
            if ($state[$f] !== $empty) {
                $state[$f] = $empty;
                $changed[] = $f;
            }
        }

        // --- trip type ----------------------------------------------------------------------------
        if (array_key_exists('trip_type', $patch)) {
            $t = self::TRIP_TYPES[strtolower(self::str($patch['trip_type']))] ?? null;
            if ($t !== null && $t !== $state['trip_type']) {
                $state['trip_type'] = $t;
                $changed[] = 'trip_type';
                if ($t === 'one_way' && $state['return_date'] !== null) {
                    $state['return_date'] = null;
                    $changed[] = 'return_date';
                }
            }
        }

        // --- multi-city: replace the whole list ---------------------------------------------------
        if (isset($patch['segments']) && is_array($patch['segments']) && $patch['segments']) {
            $list = array_slice(array_values(array_filter($patch['segments'], 'is_array')), 0, self::MAX_SEGMENTS);
            $new  = [];
            foreach ($list as $i => $seg) {
                $row = ['from' => null, 'to' => null, 'date' => null];
                foreach (['from', 'to'] as $end) {
                    if (self::str($seg[$end] ?? null) !== '') {
                        $p = self::place(self::str($seg[$end]), $resolvePlace, $state, $end, $i, $issue);
                        if ($p) {
                            $row[$end] = $p;
                        }
                    }
                }
                if (self::str($seg['date'] ?? null) !== '') {
                    $d = self::date(self::str($seg['date']), $today, 'segment_' . ($i + 1), $issue);
                    if ($d) {
                        $row['date'] = $d;
                    }
                }
                $new[] = $row;
            }
            if ($new !== $state['segments']) {
                $state['segments'] = $new;
                $changed[] = 'segments';
            }
            if (count($new) > 1 && $state['trip_type'] !== 'multi_city') {
                $state['trip_type'] = 'multi_city';
                $changed[] = 'trip_type';
                if ($state['return_date'] !== null) {
                    $state['return_date'] = null;
                    $changed[] = 'return_date';
                }
            }
        }

        // --- multi-city: edit one flight by position ----------------------------------------------
        if (isset($patch['segment']) && is_array($patch['segment'])) {
            $idx = (int) self::str($patch['segment']['index'] ?? null) - 1;
            if ($idx < 0 || $idx > count($state['segments']) || $idx >= self::MAX_SEGMENTS) {
                $issue('bad_segment', 'segments', self::str($patch['segment']['index'] ?? null));
            } else {
                self::editSegment($state, $changed, $idx, $patch['segment'], $today, $resolvePlace, $issue);
            }
        }

        // --- simple trips: origin, destination, departure date are flight 1 -----------------------
        $simple = array_intersect_key($patch, ['from' => 1, 'to' => 1, 'depart_date' => 1]);
        if ($simple) {
            self::editSegment($state, $changed, 0, [
                'from' => $patch['from'] ?? null, 'to' => $patch['to'] ?? null, 'date' => $patch['depart_date'] ?? null,
            ], $today, $resolvePlace, $issue);
        }

        // --- return date --------------------------------------------------------------------------
        if (self::str($patch['return_date'] ?? null) !== '') {
            $d = self::date(self::str($patch['return_date']), $today, 'return_date', $issue);
            if ($d && $d !== $state['return_date']) {
                $state['return_date'] = $d;
                $changed[] = 'return_date';
            }
            if ($d && $state['trip_type'] !== 'return' && $state['trip_type'] !== 'multi_city') {
                $state['trip_type'] = 'return';     // a return date means a return trip
                $changed[] = 'trip_type';
            }
        }

        // --- passengers (the model reports the NEW TOTALS) ----------------------------------------
        $pax = ['adults' => $state['adults'], 'children' => $state['children'], 'infants' => $state['infants']];
        foreach (['adults', 'children', 'infants'] as $f) {
            if (array_key_exists($f, $patch) && is_numeric($patch[$f])) {
                $pax[$f] = max(0, min(99, (int) $patch[$f]));
            }
        }
        if ($pax !== ['adults' => $state['adults'], 'children' => $state['children'], 'infants' => $state['infants']]) {
            $adults = $pax['adults'];
            if ($adults !== null && $adults < 1 && (($pax['children'] ?? 0) > 0 || ($pax['infants'] ?? 0) > 0)) {
                $issue('minor_alone', 'adults');                         // a child cannot be booked alone here
                $pax['adults'] = $state['adults'];
            } elseif ($adults !== null && $adults < 1) {
                $issue('no_adult', 'adults');
                $pax['adults'] = $state['adults'];
            }
            if ($pax['adults'] !== null && ($pax['infants'] ?? 0) > $pax['adults']) {
                $issue('infant_ratio', 'infants', (string) $pax['infants']);   // one infant per adult
                $pax['infants'] = $state['infants'];
            }
            foreach (['adults', 'children', 'infants'] as $f) {
                if ($pax[$f] !== $state[$f]) {
                    $state[$f] = $pax[$f];
                    $changed[] = $f;
                }
            }
            if (self::seats($state) >= self::GROUP_FROM && array_intersect(['adults', 'children'], $changed)) {
                $issue('group_booking', 'passengers', (string) self::seats($state));
            }
        }

        // --- preferences --------------------------------------------------------------------------
        if (array_key_exists('cabin', $patch)) {
            $c = self::CABINS[strtolower(str_replace('-', ' ', self::str($patch['cabin'])))] ?? null;
            if ($c !== null && $c !== $state['cabin']) {
                $state['cabin'] = $c;
                $changed[] = 'cabin';
            }
        }
        if (isset($patch['airlines']) && is_array($patch['airlines'])) {
            $a = [];
            foreach ($patch['airlines'] as $name) {
                $name = self::text($name, 40);
                if ($name !== null && ! in_array($name, $a, true)) {
                    $a[] = $name;
                }
            }
            $a = array_slice($a, 0, 5);
            if ($a !== $state['airlines']) {
                $state['airlines'] = $a;
                $changed[] = 'airlines';
            }
        }
        foreach (['baggage' => 80, 'notes' => 300] as $f => $max) {
            if (array_key_exists($f, $patch)) {
                $v = self::text($patch[$f], $max);
                if ($v !== null && $v !== $state[$f]) {
                    $state[$f] = $v;
                    $changed[] = $f;
                }
            }
        }
        if (array_key_exists('flexible_days', $patch) && is_numeric($patch['flexible_days'])) {
            $v = max(0, min(7, (int) $patch['flexible_days']));
            if ($v !== $state['flexible_days']) {
                $state['flexible_days'] = $v;
                $changed[] = 'flexible_days';
            }
        }
        if (array_key_exists('direct_only', $patch) && (is_bool($patch['direct_only']) || in_array($patch['direct_only'], [0, 1, '0', '1', 'true', 'false'], true))) {
            $v = filter_var($patch['direct_only'], FILTER_VALIDATE_BOOLEAN);
            if ($v !== $state['direct_only']) {
                $state['direct_only'] = $v;
                $changed[] = 'direct_only';
            }
        }

        // --- everything that is not a flight (services, destinations, ages, budget, contact) -------
        EnquiryBrief::apply($state, $patch, $today, $changed, $issue);

        // --- cross-field checks -------------------------------------------------------------------
        $first = $state['segments'][0]['date'] ?? null;
        if ($state['trip_type'] === 'return' && $state['return_date'] !== null && $first !== null && $state['return_date'] < $first) {
            $issue('return_before_departure', 'return_date', $state['return_date']);
            $state['return_date'] = null;                    // the old return no longer fits: ask, do not guess
            $changed[] = 'return_date';
        }
        if ($state['trip_type'] === 'multi_city') {
            $prev = null;
            foreach ($state['segments'] as $i => $seg) {
                if ($seg['date'] !== null && $prev !== null && $seg['date'] < $prev) {
                    $issue('segment_order', 'segment_' . ($i + 1), $seg['date']);
                    $state['segments'][$i]['date'] = null;
                    $changed[] = 'segments';
                }
                $prev = $state['segments'][$i]['date'] ?? $prev;
            }
        }

        return ['state' => $state, 'changed' => array_values(array_unique($changed)), 'issues' => $issues];
    }

    /** What must still be asked before the trip can be searched or quoted, in asking order. */
    public static function missing(array $state): array
    {
        $state = array_merge(self::blank(), $state);
        $out   = [];
        if (! EnquiryBrief::wantsFlight($state)) {
            // No flight involved: a safari, a hotel stay, a visa, a transfer, or a plain request.
            $out = EnquiryBrief::missing($state);
        } elseif ($state['trip_type'] === 'multi_city') {
            if (count($state['segments']) < 2) {
                $out[] = 'segments';
            }
            foreach ($state['segments'] as $i => $seg) {
                if (! $seg['from'] || ! $seg['to'] || ! $seg['date']) {
                    $out[] = 'segment_' . ($i + 1);
                }
            }
            if ($state['adults'] === null) {
                $out[] = 'passengers';
            }
        } else {
            $seg = $state['segments'][0] ?? ['from' => null, 'to' => null, 'date' => null];
            if (! $seg['to']) {
                $out[] = 'destination';
            }
            if (! $seg['from']) {
                $out[] = 'origin';
            }
            if (! $seg['date']) {
                $out[] = 'depart_date';
            }
            if ($state['trip_type'] === null) {
                $out[] = 'trip_type';
            } elseif ($state['trip_type'] === 'return' && ! $state['return_date']) {
                $out[] = 'return_date';
            }
            if ($state['adults'] === null) {
                $out[] = 'passengers';
            }
        }
        if (EnquiryBrief::missingChildAges($state)) {
            $out[] = 'child_ages';
        }

        return $out;
    }

    public static function isComplete(array $state): bool
    {
        return self::missing($state) === [] && ($state['pending'] ?? []) === [];
    }

    /** Seats needed (infants travel on a lap). */
    public static function seats(array $state): int
    {
        return (int) ($state['adults'] ?? 0) + (int) ($state['children'] ?? 0);
    }

    public static function isGroup(array $state): bool
    {
        return self::seats($state) >= self::GROUP_FROM;
    }

    /** One line: "Entebbe (EBB), Uganda → Dubai, United Arab Emirates". */
    public static function route(array $state): string
    {
        $parts = [];
        foreach (($state['segments'] ?? []) as $seg) {
            $parts[] = (($seg['from']['label'] ?? null) ?: '?') . ' → ' . (($seg['to']['label'] ?? null) ?: '?');
        }

        return $parts ? implode('  ·  ', $parts) : '';
    }

    public static function paxLine(array $state): string
    {
        $bits = [];
        foreach (['adults' => ['adult', 'adults'], 'children' => ['child', 'children'], 'infants' => ['infant', 'infants']] as $f => [$one, $many]) {
            $n = (int) ($state[$f] ?? 0);
            if ($n > 0) {
                $bits[] = $n . ' ' . ($n === 1 ? $one : $many);
            }
        }

        return implode(', ', $bits);
    }

    /** "20 Dec 2026" */
    public static function niceDate(?string $ymd): string
    {
        if (! $ymd || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return '';
        }
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        return (int) $m[3] . ' ' . $months[(int) $m[2] - 1] . ' ' . $m[1];
    }

    public static function tripTypeLabel(?string $t): string
    {
        return ['one_way' => 'One way', 'return' => 'Return', 'multi_city' => 'Multi-city'][$t ?? ''] ?? '';
    }

    public static function cabinLabel(?string $c): string
    {
        return ['economy' => 'Economy', 'premium_economy' => 'Premium economy', 'business' => 'Business', 'first' => 'First'][$c ?? ''] ?? '';
    }

    /**
     * The trip as short lines, for the customer summary, the staff alert and the prompt.
     * Built by code from the stored record — never by the model.
     *
     * @return string[]
     */
    public static function summaryLines(array $state): array
    {
        $state = array_merge(self::blank(), $state);
        $brief = EnquiryBrief::summaryLines($state);
        $lines = [];
        // "Services: …" leads; the rest of the brief follows the flights.
        if ($brief && str_starts_with($brief[0], 'Services: ')) {
            $lines[] = array_shift($brief);
        }
        if ($state['trip_type'] === 'multi_city') {
            foreach ($state['segments'] as $i => $seg) {
                $lines[] = 'Flight ' . ($i + 1) . ': ' . (($seg['from']['label'] ?? null) ?: '?') . ' → ' . (($seg['to']['label'] ?? null) ?: '?')
                    . ($seg['date'] ? ' · ' . self::niceDate($seg['date']) : '');
            }
        } else {
            $seg = $state['segments'][0] ?? null;
            if ($seg) {
                $lines[] = trim((self::tripTypeLabel($state['trip_type']) ?: 'Trip') . ': '
                    . (($seg['from']['label'] ?? null) ?: '?') . ' → ' . (($seg['to']['label'] ?? null) ?: '?'));
                $d = $seg['date'] ? 'Depart ' . self::niceDate($seg['date']) : '';
                if ($state['return_date']) {
                    $d .= ($d ? ' · ' : '') . 'Return ' . self::niceDate($state['return_date']);
                }
                if ($d !== '') {
                    $lines[] = $d;
                }
            }
        }
        foreach ($brief as $line) {
            $lines[] = $line;
        }
        $p = self::paxLine($state);
        if ($p !== '' || $state['cabin']) {
            $lines[] = trim($p . ($p !== '' && $state['cabin'] ? ' · ' : '') . self::cabinLabel($state['cabin']));
        }
        if (is_array($state['travellers']) && $state['travellers'] !== []) {
            $lines[] = 'Travellers: ' . \App\Services\Travel\Passport\Travellers::namesLine($state['travellers']);
        }
        $prefs = [];
        if ($state['airlines']) {
            $prefs[] = 'Prefers ' . implode(', ', $state['airlines']);
        }
        if ($state['direct_only']) {
            $prefs[] = 'direct flights only';
        }
        if ($state['flexible_days']) {
            $prefs[] = 'flexible ±' . $state['flexible_days'] . ' day' . ($state['flexible_days'] === 1 ? '' : 's');
        }
        if ($state['baggage']) {
            $prefs[] = 'baggage: ' . $state['baggage'];
        }
        if ($prefs) {
            $lines[] = ucfirst(implode(' · ', $prefs));
        }
        if ($state['notes']) {
            $lines[] = 'Note: ' . $state['notes'];
        }

        return $lines;
    }

    // ---------------------------------------------------------------------------------------------

    private static function editSegment(array &$state, array &$changed, int $idx, array $edit, string $today, callable $resolvePlace, callable $issue): void
    {
        $seg = $state['segments'][$idx] ?? ['from' => null, 'to' => null, 'date' => null];
        $was = $seg;
        foreach (['from', 'to'] as $end) {
            if (self::str($edit[$end] ?? null) !== '') {
                $p = self::place(self::str($edit[$end]), $resolvePlace, $state, $end, $idx, $issue);
                if ($p) {
                    $seg[$end] = $p;
                }
            }
        }
        if (self::str($edit['date'] ?? null) !== '') {
            $d = self::date(self::str($edit['date']), $today, $idx === 0 ? 'depart_date' : 'segment_' . ($idx + 1), $issue);
            if ($d) {
                $seg['date'] = $d;
            }
        }
        // Flying from a city to itself is a mistake in the reading, not a trip.
        if ($seg['from'] && $seg['to'] && array_intersect($seg['from']['codes'], $seg['to']['codes'])) {
            $issue('same_place', $idx === 0 ? 'destination' : 'segment_' . ($idx + 1), (string) $seg['to']['label']);
            $seg = $was;
        }
        if ($seg !== $was) {
            $state['segments'][$idx] = $seg;
            $state['segments'] = array_values($state['segments']);
            foreach (['from' => 'origin', 'to' => 'destination', 'date' => 'depart_date'] as $k => $name) {
                if ($seg[$k] !== $was[$k]) {
                    $changed[] = $idx === 0 ? $name : 'segments';
                }
            }
        }
    }

    /** Resolve a typed place. Returns the place, or null after recording why not. */
    private static function place(string $typed, callable $resolvePlace, array &$state, string $end, int $idx, callable $issue): ?array
    {
        $field = $idx === 0 ? ($end === 'from' ? 'origin' : 'destination') : 'segment_' . ($idx + 1);
        $r = $resolvePlace($typed);
        // Whatever happens, the earlier open question about this field is superseded.
        $state['pending'] = array_values(array_filter($state['pending'], fn ($p) => ! ($p['field'] === $field && ($p['end'] ?? $end) === $end)));

        if (($r['status'] ?? '') === 'ok') {
            return ['label' => $r['label'], 'city' => $r['city'], 'country' => $r['country'], 'codes' => $r['codes'], 'input' => self::text($typed, 60) ?? ''];
        }
        if (($r['status'] ?? '') === 'ambiguous') {
            $labels = array_map(fn ($o) => (string) $o['label'], (array) ($r['options'] ?? []));
            $state['pending'][] = ['kind' => 'ambiguous_place', 'field' => $field, 'end' => $end, 'input' => self::text($typed, 60), 'options' => $labels];
            $issue('ambiguous_place', $field, implode(' | ', $labels));

            return null;
        }
        $issue(($r['hint'] ?? '') === 'country' ? 'need_city' : 'unknown_place', $field, self::text((string) ($r['label'] ?? $typed), 60) ?? '');

        return null;
    }

    private static function date(string $v, string $today, string $field, callable $issue): ?string
    {
        $v = trim($v);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $issue('bad_date', $field, mb_substr($v, 0, 20));

            return null;
        }
        if ($v < $today) {
            $issue('date_past', $field, $v);

            return null;
        }
        $limit = date('Y-m-d', strtotime($today . ' +' . self::MAX_DAYS_AHEAD . ' days'));
        if ($v > $limit) {
            $issue('date_far', $field, $v);

            return null;
        }

        return $v;
    }

    /** A scalar as a trimmed string; anything else (array, null, object) is the empty string. */
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
