<?php declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\BusinessSuggestion;
use App\Models\CustomerProfile;
use App\Models\Tenant;

/**
 * v4.62 — turning a discovery run into rows a person can decide on.
 *
 * This is the class Milestone 1 deliberately stopped short of. `DiscoveryService` groups, counts and
 * logs; it writes nothing. This writes — to the review queue only, never to a customer record.
 *
 * Three things it has to get right, and each is a way a queue becomes useless:
 *
 *   MATCH FIRST     A discovered business that is already a customer must arrive as an ENRICHMENT
 *                   of that record, not as a second copy of it. The match is on normalised phone,
 *                   which is what customer identity in this system already is.
 *
 *   SAY NOTHING     A row that would write no field tells the reviewer nothing and trains them to
 *   USELESS         approve without reading, which is how the empty-field-only rule stops
 *                   protecting anyone.
 *
 *   NEVER TWICE     The same business found by two providers, or by the same provider a month
 *                   later, updates the row that is already waiting rather than adding to a pile.
 */
final class DiscoveryQueue
{
    /**
     * @param  array<string,array>  $businesses  DiscoveryService::run()['businesses']
     * @return array{created:int, updated:int, matched:int, skipped:int, reasons:array<string,int>}
     */
    public function fill(Tenant $t, array $businesses, ?string $provider = null): array
    {
        $created = 0; $updated = 0; $matched = 0; $skipped = 0; $reasons = [];
        $skip = function (string $why) use (&$skipped, &$reasons) {
            $skipped++;
            $reasons[$why] = ($reasons[$why] ?? 0) + 1;
        };

        $profiles = $this->profilesByPhone($t);
        $pending  = $this->pendingByIdentity($t);

        foreach ($businesses as $id => $b) {
            $best = $b['best'] ?? [];
            if (! $best) {
                $skip('nothing_found');
                continue;
            }

            // Match on the phone, because that is what identity is here — and the phone we match on
            // is the one BusinessIdentity already normalised into the key, not a second parse.
            $digits  = str_starts_with((string) $id, 'p:') ? substr((string) $id, 2) : '';
            $profile = $digits !== '' ? ($profiles[$digits] ?? null) : null;

            $current  = $profile ? $this->columnsOf($profile) : [];
            $writable = BusinessMatch::writable($best, $current);

            if (! BusinessMatch::isWorthReviewing($best, $writable)) {
                // Two different silences, and the difference is worth logging: one means the source
                // gave nothing usable, the other means the shop already knows everything it said.
                $skip($writable === [] && $profile ? 'already_known' : 'nothing_to_add');
                continue;
            }

            $row = [
                'tenant_id'           => $t->id,
                'identity_key'        => (string) $id,
                'customer_profile_id' => $profile?->id,
                'name'                => mb_substr(trim((string) ($best['name']['value'] ?? '')), 0, 200),
                'phone'               => mb_substr(trim((string) ($best['phone']['value'] ?? '')), 0, 40),
                'city'                => mb_substr(trim((string) ($best['city']['value'] ?? '')), 0, 80),
                'findings'            => BusinessMatch::preview($best, $writable),
                'proposed'            => $writable,
                'provider'            => $provider ?? $this->firstSource($best),
                'confidence'          => BusinessMatch::topConfidence($best),
                'status'              => 'pending',
            ];

            try {
                if (isset($pending[(string) $id])) {
                    // Already waiting. A second sighting usually knows something the first did not,
                    // so the row is refreshed rather than duplicated — and the reviewer still makes
                    // exactly one decision about exactly one business.
                    $existing = $pending[(string) $id];
                    $existing->fill($row)->save();
                    $updated++;
                } else {
                    $pending[(string) $id] = BusinessSuggestion::create($row);
                    $created++;
                }
                if ($profile) {
                    $matched++;
                }
            } catch (\Throwable $e) {
                $skip('write_failed');
                \Log::warning('discovery queue row not written: ' . $e->getMessage());
            }
        }

        return ['created' => $created, 'updated' => $updated, 'matched' => $matched,
                'skipped' => $skipped, 'reasons' => $reasons];
    }

    /** @return array<string,CustomerProfile> normalised digits => profile */
    private function profilesByPhone(Tenant $t): array
    {
        $out = [];
        CustomerProfile::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->get(array_merge(['id', 'phone'], array_values(array_unique(BusinessMatch::COLUMN))))
            ->each(function ($p) use (&$out) {
                $d = preg_replace('/[^0-9]/', '', (string) $p->phone);
                // First wins. A duplicate phone is a data problem with its own tool; picking one
                // arbitrarily here at least does so consistently between runs.
                if ($d !== '' && ! isset($out[$d])) {
                    $out[$d] = $p;
                }
            });

        return $out;
    }

    /** @return array<string,BusinessSuggestion> */
    private function pendingByIdentity(Tenant $t): array
    {
        $out = [];
        BusinessSuggestion::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('status', 'pending')
            ->get()
            ->each(function ($s) use (&$out) {
                $out[(string) $s->identity_key] ??= $s;
            });

        return $out;
    }

    /** @return array<string,mixed> the columns BusinessMatch is allowed to consider */
    private function columnsOf(CustomerProfile $p): array
    {
        $out = [];
        foreach (array_unique(BusinessMatch::COLUMN) as $column) {
            $out[$column] = $p->{$column} ?? null;
        }

        return $out;
    }

    private function firstSource(array $best): ?string
    {
        foreach ($best as $f) {
            $s = trim((string) ($f['source'] ?? ''));
            if ($s !== '') {
                return $s;
            }
        }

        return null;
    }
}
