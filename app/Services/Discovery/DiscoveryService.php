<?php declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\DiscoveryRun;
use App\Models\Tenant;
use App\Services\Discovery\Dto\BusinessFinding;

/**
 * v4.59 — run a provider, group what it found, log what happened.
 *
 * Milestone 1 stops HERE, deliberately: nothing is written to `customer_profiles`. Discoveries are
 * grouped, deduplicated within the run, counted and logged, and returned. Where they go next is the
 * review queue in a later milestone, and building the destination before the pipeline is how a
 * review queue ends up full of rows nobody trusts.
 *
 * The one thing this class guarantees: **a provider cannot break a run.** Every call is wrapped,
 * every failure is logged as a failed run with its reason, and the other providers carry on.
 */
final class DiscoveryService
{
    public function __construct(private DiscoveryRegistry $registry) {}

    /**
     * @return array{
     *   businesses: array<string,array>, runs: array<int,array>,
     *   found: int, usable: int, duplicates: int
     * }
     */
    public function run(Tenant $t, array $query, ?int $userId = null, ?array $only = null): array
    {
        $providers = $this->registry->enabled();
        if ($only) {
            $providers = array_intersect_key($providers, array_flip($only));
        }

        $businesses = [];
        $runs = [];
        $totFound = 0; $totUsable = 0; $totDupes = 0;

        foreach ($providers as $key => $provider) {
            $found = 0; $usable = 0; $dupes = 0; $status = 'ok'; $note = null;

            try {
                $results = $provider->discover($query);
                $found = count($results);

                foreach ($results as $r) {
                    $best = BusinessIdentity::best($r['findings'] ?? []);
                    $id = BusinessIdentity::key(
                        $best['phone']['value'] ?? null,
                        $best['name']['value'] ?? null,
                        $best['city']['value'] ?? null,
                    );
                    if ($id === '') {
                        continue;            // no phone and no name: nothing to be the same as
                    }
                    $usable++;

                    if (isset($businesses[$id])) {
                        $dupes++;
                        // A second sighting is not a waste — it may know a field the first did not,
                        // and a higher-confidence value for one it did.
                        $businesses[$id]['findings'] = array_merge(
                            $businesses[$id]['findings'],
                            array_values(array_filter($r['findings'] ?? [], fn ($f) => $f instanceof BusinessFinding))
                        );
                        $businesses[$id]['best'] = BusinessIdentity::best($businesses[$id]['findings']);
                        $businesses[$id]['sources'] = BusinessIdentity::sources($businesses[$id]['best']);
                        continue;
                    }

                    $businesses[$id] = [
                        'key'       => $id,
                        'reachable' => BusinessIdentity::isReachable($id),
                        'findings'  => array_values(array_filter($r['findings'] ?? [], fn ($f) => $f instanceof BusinessFinding)),
                        'best'      => $best,
                        'sources'   => BusinessIdentity::sources($best),
                    ];
                }

                if ($found === 0) {
                    // Not an error, but never silent: a provider returning nothing every time is the
                    // failure that takes weeks to notice, because it looks like an empty market.
                    $status = 'empty';
                    $note = 'provider returned no businesses';
                }
            } catch (\Throwable $e) {
                // The contract says a provider never throws. This is here for when one does anyway.
                $status = 'failed';
                $note = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 400);
            }

            $totFound += $found; $totUsable += $usable; $totDupes += $dupes;
            $runs[] = $this->log($t, $key, $status, $query, $found, $usable, $dupes, $note, $userId);
        }

        return [
            'businesses' => $businesses,
            'runs' => $runs,
            'found' => $totFound,
            'usable' => $totUsable,
            'duplicates' => $totDupes,
        ];
    }

    /** Never fatal: a run that cannot be logged still happened, and losing it is better than losing it AND the results. */
    private function log(Tenant $t, string $provider, string $status, array $query, int $found, int $usable, int $dupes, ?string $note, ?int $userId): array
    {
        $row = [
            'provider' => $provider, 'status' => $status, 'found' => $found,
            'usable' => $usable, 'duplicates' => $dupes, 'note' => $note,
        ];
        try {
            DiscoveryRun::create($row + [
                'tenant_id' => $t->id,
                // The raw CSV can be megabytes and is already the user's own file; keep the shape of
                // the request, not its payload.
                'query' => $this->summarise($query),
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('discovery run not logged: ' . $e->getMessage());
        }

        return $row;
    }

    /** What was asked for, minus anything bulky or personal. */
    private function summarise(array $query): array
    {
        $out = [];
        foreach (['area', 'category', 'radius_km', 'limit'] as $k) {
            if (isset($query[$k]) && $query[$k] !== '') {
                $out[$k] = is_scalar($query[$k]) ? $query[$k] : null;
            }
        }
        if (! empty($query['csv'])) {
            $out['csv_bytes'] = strlen((string) $query['csv']);
        }

        return $out;
    }
}
