<?php

namespace App\Services\Travel\Passport;

/**
 * PURE. What a trip learns from the travellers' passports and ID cards, and how they are shown.
 *
 * A traveller here is the plain array TravelTraveller::facts() returns (name, date_of_birth,
 * expiry_date, document_type, nationality, last4…). It never contains the document number.
 *
 * The customer's own words win: passports fill the party (adults, children's ages) only when the
 * customer has not stated it, or when the party on record is exactly what earlier passports produced.
 * A party the customer stated is never changed; at most, missing children's ages are filled in.
 */
final class Travellers
{
    /** ICAO: a passport should be valid six months beyond the stay; many countries enforce it. */
    public const VALIDITY_MONTHS = 6;

    public static function fullName(string $given, string $surname): string
    {
        return trim(preg_replace('/\s+/', ' ', trim($given) . ' ' . trim($surname)) ?? '');
    }

    /** "…02C3": the end of a document number, enough for staff to match it to the document in hand. */
    public static function mask(string $last4): string
    {
        return $last4 === '' ? '' : '…' . $last4;
    }

    public static function last4(string $number): string
    {
        return substr($number, -4);
    }

    /** Keyed hash of the document, so the same passport sent twice is recognised without decrypting anything. */
    public static function fingerprint(string $type, string $country, string $number, string $key): string
    {
        return hash_hmac('sha256', strtoupper($type . '|' . $country . '|' . $number), $key);
    }

    /** The day ages are counted on: the first travel date if it is still ahead, otherwise today. */
    public static function travelDate(array $state, string $today): string
    {
        $d = $state['segments'][0]['date'] ?? null;
        $d = $d ?: ($state['brief']['start_date'] ?? null);

        return is_string($d) && $d > $today ? $d : $today;
    }

    /** The last day of the trip, when known. */
    public static function returnDate(array $state): ?string
    {
        $d = $state['return_date'] ?? null;
        $d = $d ?: ($state['brief']['end_date'] ?? null);

        return is_string($d) && $d !== '' ? $d : null;
    }

    public static function age(array $t, string $on): ?int
    {
        return empty($t['date_of_birth']) ? null : Mrz::age((string) $t['date_of_birth'], $on);
    }

    /**
     * Is the document still good for this trip? null = fine (or unknown),
     * 'expired' = past its expiry date today, 'short' = expires within six months of the trip's end.
     */
    public static function validity(array $t, string $today, string $travelDate, ?string $returnDate): ?string
    {
        $exp = (string) ($t['expiry_date'] ?? '');
        if ($exp === '') {
            return null;
        }
        if ($exp < $today) {
            return 'expired';
        }
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate ?? $travelDate);
        if ($end && $exp < $end->modify('+' . self::VALIDITY_MONTHS . ' months')->format('Y-m-d')) {
            return 'short';
        }

        return null;
    }

    /**
     * The party, as these travellers' documents show it.
     *
     * @return array{adults:int,children:int,infants:int,child_ages:int[]}
     */
    public static function party(array $travellers, string $on): array
    {
        $adults = 0;
        $ages = [];
        foreach ($travellers as $t) {
            $a = self::age($t, $on);
            if ($a === null) {
                continue;
            }
            $a >= 18 ? $adults++ : $ages[] = $a;
        }

        return [
            'adults'     => $adults,
            'children'   => count(array_filter($ages, fn ($a) => $a >= 2)),
            'infants'    => count(array_filter($ages, fn ($a) => $a < 2)),
            'child_ages' => $ages,
        ];
    }

    /**
     * The TRIP patch to apply after a document is read (EnquirySlots::apply format), or [] for none.
     *
     * @param  array $before  travellers on the trip before this document
     * @param  array $after   travellers including this document
     */
    public static function partyPatch(array $state, array $before, array $after, string $on): array
    {
        $now  = self::party($after, $on);
        $then = self::party($before, $on);
        $stated = $state['adults'] !== null || $state['children'] !== null || $state['infants'] !== null;
        $recordAges = array_values((array) ($state['brief']['child_ages'] ?? []));

        $fromPassports = $stated && $before !== []
            && (int) $state['adults'] === $then['adults']
            && (int) $state['children'] === $then['children']
            && (int) $state['infants'] === $then['infants']
            && $recordAges === $then['child_ages'];

        if (! $stated || $fromPassports) {
            if ($now['adults'] + count($now['child_ages']) === 0) {
                return [];
            }
            $patch = ['adults' => $now['adults'], 'children' => $now['children'], 'infants' => $now['infants']];
            if ($now['child_ages'] !== [] || $recordAges !== []) {
                $patch['child_ages'] = $now['child_ages'];
            }

            return $patch;
        }

        // The customer stated the party: only fill children's ages they have not given, and only when
        // the documents show exactly that many children.
        $young = (int) $state['children'] + (int) $state['infants'];
        if ($recordAges === [] && $young > 0 && count($now['child_ages']) === $young) {
            return ['child_ages' => $now['child_ages']];
        }

        return [];
    }

    /** More documents than travellers on record: worth asking whether someone else is travelling. */
    public static function exceedsParty(array $state, int $documents): bool
    {
        $party = (int) $state['adults'] + (int) $state['children'] + (int) $state['infants'];

        return $party > 0 && $documents > $party;
    }

    /** For the trip state (and so the summary and the AI's view of the trip): names and ages only. */
    public static function stateEntries(array $travellers, string $on): array
    {
        return array_map(fn (array $t) => ['name' => (string) $t['name'], 'age' => self::age($t, $on)], array_values($travellers));
    }

    /** "ANNA MARIA ERIKSSON (52), LINA ERIKSSON (8)" */
    public static function namesLine(array $entries): string
    {
        return implode(', ', array_map(fn (array $e) => $e['name'] . ($e['age'] !== null ? ' (' . $e['age'] . ')' : ''), $entries));
    }

    public static function documentLabel(string $type): string
    {
        return str_starts_with(strtoupper($type), 'P') ? 'passport' : (str_starts_with(strtoupper($type), 'V') ? 'visa' : 'ID card');
    }

    /** The message the customer receives once their document has been read. */
    public static function customerLine(array $t, string $on, ?string $validity, bool $duplicate): string
    {
        $doc = self::documentLabel((string) ($t['document_type'] ?? ''));
        $age = self::age($t, $on);
        $out = $duplicate
            ? "I already have this {$doc}: *{$t['name']}*."
            : '✅ ' . ucfirst($doc) . " read: *{$t['name']}*" . ($age !== null ? ", age {$age}" : '') . '.';
        $out .= "\nThe file itself has not been kept. If the name is not spelt exactly as on the {$doc}, please tell me.";
        $exp = self::niceDate((string) ($t['expiry_date'] ?? ''));
        if ($validity === 'expired') {
            $out .= "\nPlease note: this {$doc} shows an expiry date of {$exp}, which has passed. Our team will advise you.";
        } elseif ($validity === 'short') {
            $out .= "\nPlease note: this {$doc} expires on {$exp}, less than six months after your trip. Many countries require six months' validity; our team will advise you.";
        }

        return $out;
    }

    /** One line per traveller for staff. The document number appears masked only. */
    public static function staffLine(array $t, string $on, ?string $validity): string
    {
        $age = self::age($t, $on);
        $parts = [
            $t['name'],
            $age !== null ? $age . ' yrs' : null,
            ! empty($t['date_of_birth']) ? 'born ' . self::niceDate((string) $t['date_of_birth']) : null,
            ($t['sex'] ?? '') !== '' && ($t['sex'] ?? '') !== 'X' ? $t['sex'] : null,
            ($t['nationality'] ?? '') !== '' ? $t['nationality'] : null,
            self::documentLabel((string) ($t['document_type'] ?? '')) . ' ' . self::mask((string) ($t['last4'] ?? '')),
            ! empty($t['expiry_date']) ? 'expires ' . self::niceDate((string) $t['expiry_date']) : null,
        ];
        $line = implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
        if ($validity === 'expired') {
            $line .= ' ⚠ EXPIRED';
        } elseif ($validity === 'short') {
            $line .= ' ⚠ under 6 months validity';
        }

        return $line;
    }

    public static function niceDate(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $d ? $d->format('j M Y') : $ymd;
    }
}
