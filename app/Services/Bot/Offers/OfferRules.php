<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — active / priority / replace rules. Pure logic, no framework deps.
 *
 * All time reasoning is in unix seconds so it is testable without the framework. The model
 * layer (DailyOfferService) feeds plain arrays here and acts on the decisions.
 */
class OfferRules
{
    /** Serving priority across offer types (lower = shown first). */
    private const RANK = [
        OfferTypeClassifier::DAILY_THALI => 0,
        OfferTypeClassifier::FRESH       => 1,
        OfferTypeClassifier::FESTIVAL    => 2,
        OfferTypeClassifier::WEEKEND     => 3,
        OfferTypeClassifier::SPECIAL     => 4,
    ];

    public static function rank(string $type): int
    {
        return self::RANK[$type] ?? 9;
    }

    /**
     * Is an offer live right now? is_active true AND inside its [valid_from, valid_until] window
     * (either bound may be null = open-ended).
     * @param array{is_active:bool,valid_from:?int,valid_until:?int} $o
     */
    public static function isActiveAt(array $o, int $now): bool
    {
        if (empty($o['is_active'])) return false;
        $from = $o['valid_from'] ?? null;
        $until = $o['valid_until'] ?? null;
        if ($from !== null && $now < (int) $from) return false;
        if ($until !== null && $now > (int) $until) return false;
        return true;
    }

    /**
     * A NEW offer auto-replaces existing active offers of the SAME type (one live offer per type
     * per tenant — "today's thali" supersedes yesterday's). Other types are left untouched.
     */
    public static function supersedes(string $newType, string $oldType): bool
    {
        return $newType === $oldType;
    }

    /**
     * Filter to the active offers (optionally restricted to a set of preferred types) and order
     * them by type rank, then by most-recent valid_from. Returns the input rows re-ordered.
     *
     * @param array<int,array> $offers each: type, is_active, valid_from, valid_until, created_at
     * @param string[]         $preferTypes  if non-empty, keep only these types
     */
    public static function activeSorted(array $offers, int $now, array $preferTypes = []): array
    {
        $live = array_values(array_filter($offers, function ($o) use ($now, $preferTypes) {
            if (! self::isActiveAt($o, $now)) return false;
            if ($preferTypes && ! in_array($o['type'] ?? '', $preferTypes, true)) return false;
            return true;
        }));

        usort($live, function ($a, $b) use ($preferTypes) {
            // when a kind was requested, honour its preference order first
            if ($preferTypes) {
                $ra = array_search($a['type'] ?? '', $preferTypes, true);
                $rb = array_search($b['type'] ?? '', $preferTypes, true);
                $ra = $ra === false ? 99 : $ra;
                $rb = $rb === false ? 99 : $rb;
                if ($ra !== $rb) return $ra <=> $rb;
            } else {
                $ra = self::rank($a['type'] ?? '');
                $rb = self::rank($b['type'] ?? '');
                if ($ra !== $rb) return $ra <=> $rb;
            }
            return (int) ($b['valid_from'] ?? 0) <=> (int) ($a['valid_from'] ?? 0);   // newest first
        });

        return $live;
    }

    /**
     * Default validity window for a freshly captured offer, in unix seconds.
     * daily_thali / fresh_today expire at end of the local day; weekend at end of Sunday;
     * special at +24h; festival open-ended (owner clears it). Returns [from, until|null].
     *
     * @return array{0:int,1:?int}
     */
    public static function defaultWindow(string $type, int $now, int $endOfDay, int $endOfWeekend): array
    {
        return match ($type) {
            OfferTypeClassifier::DAILY_THALI, OfferTypeClassifier::FRESH => [$now, $endOfDay],
            OfferTypeClassifier::WEEKEND  => [$now, $endOfWeekend],
            OfferTypeClassifier::SPECIAL  => [$now, $now + 86400],
            OfferTypeClassifier::FESTIVAL => [$now, null],
            default                       => [$now, $endOfDay],
        };
    }

    /**
     * Pick the offer a conversation is talking about. Priority: the pinned offer if it is still
     * in the active set (conversation context), otherwise the top-ranked active offer.
     *
     * @param array<int,array> $offers  already active+ranked (from activeSorted)
     */
    public static function pickContext(array $offers, ?int $pinnedId): ?array
    {
        if (! $offers) return null;

        if ($pinnedId) {
            foreach ($offers as $o) {
                if ((int) ($o['id'] ?? 0) === (int) $pinnedId) return $o;
            }
        }
        return $offers[0];
    }
}
