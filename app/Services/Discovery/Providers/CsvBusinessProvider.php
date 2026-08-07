<?php declare(strict_types=1);

namespace App\Services\Discovery\Providers;

use App\Services\Discovery\Contracts\BusinessDiscoveryProvider;
use App\Services\Discovery\Dto\BusinessFinding;

/**
 * v4.59 — businesses from a spreadsheet.
 *
 * The first provider, and shipped WITH the contract on purpose: an interface designed against zero
 * implementations is designed wrong. `ResearchProvider` is good because it was written against two
 * real sources. This is the equivalent — and it happens to be the only source available today that
 * raises no terms-of-service question at all, so it earns its place twice over.
 *
 * A rep with a list from a trade association, a market-day walk with a notebook, or an export from
 * whatever the shop used before: all of it arrives this way.
 *
 * Confidence is high but not certain (0.9). A person typed it, which beats any scraper, but people
 * transpose digits — and the whole point of the review queue is that nothing here is written
 * without somebody looking.
 */
final class CsvBusinessProvider implements BusinessDiscoveryProvider
{
    /** Header aliases, normalised: lowercase, punctuation to spaces, collapsed. */
    private const ALIASES = [
        'name'           => ['name', 'business', 'business name', 'company', 'company name', 'shop', 'shop name', 'trading name'],
        'phone'          => ['phone', 'telephone', 'tel', 'mobile', 'whatsapp', 'phone number', 'contact', 'contact number'],
        'alt_phone'      => ['alt phone', 'alternate phone', 'phone 2', 'second phone', 'other phone'],
        'email'          => ['email', 'e mail', 'email address'],
        'website'        => ['website', 'web', 'url', 'site', 'web address'],
        'address'        => ['address', 'street', 'location', 'physical address'],
        'city'           => ['city', 'town', 'area', 'district', 'region'],
        'country'        => ['country'],
        'category'       => ['category', 'type', 'business type', 'segment', 'trade'],
        'contact_person' => ['contact person', 'owner', 'manager', 'person', 'contact name'],
        'latitude'       => ['latitude', 'lat'],
        'longitude'      => ['longitude', 'lng', 'long', 'lon'],
        'size'           => ['size', 'business size'],
        'external_id'    => ['id', 'external id', 'reference', 'ref'],
    ];

    private const CONFIDENCE = 0.9;

    /** No more than this from one paste — a run that never finishes helps nobody. */
    private const MAX_ROWS = 5000;

    public function key(): string { return 'csv'; }

    public function label(): string { return 'Spreadsheet / CSV'; }

    /** Always available. There is nothing to configure and nothing to go down. */
    public function isEnabled(): bool { return true; }

    public function discover(array $query): array
    {
        try {
            return $this->parse((string) ($query['csv'] ?? ''));
        } catch (\Throwable $e) {
            // The contract: never throw. A malformed paste yields nothing and the run logs it.
            return [];
        }
    }

    /** @return array<int,array{key:string, findings:BusinessFinding[]}> */
    private function parse(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }
        // A spreadsheet saved on Windows arrives with a byte-order mark that would otherwise make the
        // first column header unmatchable — and the first column is almost always the name.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        $lines = preg_split('/\r\n|\r|\n/', $csv);
        $head  = str_getcsv((string) array_shift($lines));
        $map   = $this->resolve($head);
        if (! isset($map['name'])) {
            return [];                       // without a name there is nothing to call a business
        }

        $out = [];
        $n = 0;
        foreach ($lines as $i => $line) {
            if (trim($line) === '' || $n >= self::MAX_ROWS) {
                continue;
            }
            $cols = str_getcsv($line);
            $get = fn (string $f) => isset($map[$f], $cols[$map[$f]]) ? trim((string) $cols[$map[$f]]) : '';

            if ($get('name') === '') {
                continue;                    // a row with no name is a blank line with commas in it
            }
            $n++;

            $findings = [];
            foreach (array_keys(self::ALIASES) as $field) {
                $v = $get($field);
                if ($v === '') {
                    continue;                // silence beats a guess — the contract's first rule
                }
                $findings[] = BusinessFinding::make(
                    $field, $v, self::CONFIDENCE, $this->key(), 'row ' . ($i + 2) . ' of the sheet'
                );
            }

            $out[] = ['key' => 'row:' . ($i + 2), 'findings' => $findings];
        }

        return $out;
    }

    /** @return array<string,int> field => column index */
    private function resolve(array $header): array
    {
        $norm = array_map(function ($h) {
            $x = mb_strtolower(trim((string) $h));
            $x = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $x);

            return trim((string) preg_replace('/\s+/u', ' ', $x));
        }, $header);

        $map = [];
        foreach (self::ALIASES as $field => $names) {
            foreach ($names as $alias) {
                $i = array_search($alias, $norm, true);
                if ($i !== false) {
                    $map[$field] = (int) $i;
                    break;
                }
            }
        }

        return $map;
    }
}
