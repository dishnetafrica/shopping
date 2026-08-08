<?php declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\FollowupProposal;
use App\Models\SearchEvent;
use App\Models\VisitItem;
use App\Models\Tenant;
use App\Services\Bot\PartRequestService;
use Illuminate\Support\Facades\Cache;

/**
 * v4.58 — what customers asked for that you do not sell.
 *
 * Two sources, both of which the system has been recording for months and neither of which has ever
 * been added up:
 *
 *   SEARCHES  `search_events` rows with no results — somebody typed it into the storefront or the
 *             bot and got nothing. Already shown on Storefront Insights as a list of terms.
 *   ASKS      `FollowupProposal` rows with trigger `part_request` — the bot noticing a customer
 *             asked for something not in the catalogue. **Recorded one row per customer**, which is
 *             right for following that person up and useless for deciding what to stock.
 *   VISITS    `visit_items` with `stocked = false` (v4.63) — something a rep was asked for standing
 *             in the shop. The best of the three: a person said it to another person, and the
 *             business it came from has a phone on its record, so it arrives with a caller already
 *             attached.
 *
 * The second is the one worth combining. "Musa asked for brake pads" is a follow-up. "Nine
 * customers asked for brake pads this month, and here are their numbers" is a purchase decision
 * with a callback list attached — and the callback list is the part that turns stock into money,
 * because the people who asked are the people who will buy it the week it lands.
 *
 * Searches carry no phone number: `search_events` has nowhere to put one. So a term that only ever
 * appears as a search shows demand with nobody to ring, and the screen says so rather than implying
 * a list that does not exist.
 */
final class UnmetDemandService
{
    /** Long enough to see a pattern, short enough that it is still true. */
    private const WINDOW_DAYS = 90;

    /** More than this on one screen is a spreadsheet, not a decision. */
    private const MAX_ROWS = 40;

    /**
     * @return array{rows: array<int,array>, window: int, generated_at: string}
     */
    public function forTenant(Tenant $t, bool $fresh = false): array
    {
        $key = 'unmet_demand:' . $t->id;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 1800, fn () => $this->build($t));
    }

    private function build(Tenant $t): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $group = [];

        $add = function (string $raw, ?string $phone, $when, string $source) use (&$group) {
            if (! DemandKey::usable($raw)) {
                return;
            }
            $k = DemandKey::key($raw);
            $group[$k] ??= ['variants' => [], 'times' => 0, 'phones' => [], 'last' => null, 'sources' => []];
            $group[$k]['variants'][] = $raw;
            $group[$k]['times']++;
            $group[$k]['sources'][$source] = true;

            $digits = preg_replace('/[^0-9]/', '', (string) $phone);
            if ($digits !== '') {
                $group[$k]['phones'][$digits] = true;
            }
            $ts = $when ? \Illuminate\Support\Carbon::parse($when) : null;
            if ($ts && (! $group[$k]['last'] || $ts->gt($group[$k]['last']))) {
                $group[$k]['last'] = $ts;
            }
        };

        // Searches that found nothing. No customer attached — the table has nowhere to keep one.
        try {
            SearchEvent::withoutGlobalScopes()
                ->where('tenant_id', $t->id)
                ->where('created_at', '>=', $since)
                ->where(fn ($q) => $q->where('type', 'zero_result')->orWhere('results', 0))
                ->whereNotNull('query')
                ->get(['query', 'created_at'])
                ->each(fn ($e) => $add((string) $e->query, null, $e->created_at, 'search'));
        } catch (\Throwable $e) {
            // an analytics table that is missing must not take the screen down
        }

        // Things the bot was asked for by name. These DO carry a phone, which is the whole point.
        try {
            FollowupProposal::withoutGlobalScopes()
                ->where('tenant_id', $t->id)
                ->where('trigger', 'part_request')
                ->where('created_at', '>=', $since)
                ->get(['customer_phone', 'trigger_reason', 'created_at'])
                ->each(function ($p) use ($add) {
                    $part = PartRequestService::partFromReason((string) $p->trigger_reason);
                    $add($part, (string) $p->customer_phone, $p->created_at, 'asked');
                });
        } catch (\Throwable $e) {
        }

        // Things a rep was asked for standing in the shop. The third source, and the best of the
        // three: a person said it out loud to another person, and the business it came from has a
        // phone number on its record — so this demand arrives with a callback list already attached.
        try {
            VisitItem::withoutGlobalScopes()
                ->where('visit_items.tenant_id', $t->id)
                ->where('visit_items.stocked', false)
                ->where('visit_items.created_at', '>=', $since)
                ->join('visits', 'visits.id', '=', 'visit_items.visit_id')
                ->leftJoin('customer_profiles', 'customer_profiles.id', '=', 'visits.customer_profile_id')
                ->get(['visit_items.name', 'visit_items.created_at', 'customer_profiles.phone'])
                ->each(fn ($i) => $add((string) $i->name, (string) ($i->phone ?? ''), $i->created_at, 'visit'));
        } catch (\Throwable $e) {
            // the visits tables may not be migrated yet; the screen must still render
        }

        $rows = [];
        foreach ($group as $g) {
            $phones = array_keys($g['phones']);
            $rows[] = [
                'label'     => DemandKey::label($g['variants']),
                'times'     => (int) $g['times'],
                'customers' => count($phones),
                'phones'    => array_slice($phones, 0, 20),
                'last'      => $g['last'] ? $g['last']->toDateString() : null,
                'sources'   => array_keys($g['sources']),
                // Distinct people asking beats one person asking ten times, which is usually one
                // person having a bad day rather than a market.
                'rank'      => count($phones) * 10 + (int) $g['times'],
            ];
        }

        usort($rows, fn ($a, $b) => [$b['rank'], $b['times']] <=> [$a['rank'], $a['times']]);

        return [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'window' => self::WINDOW_DAYS,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public static function forget(int $tenantId): void
    {
        try { Cache::forget('unmet_demand:' . $tenantId); } catch (\Throwable $e) {}
    }
}
