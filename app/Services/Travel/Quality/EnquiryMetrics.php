<?php

namespace App\Services\Travel\Quality;

use App\Services\Travel\Enquiry\TravelPipeline;

/**
 * AI-quality and funnel measures for travel enquiries (Travel Vertical · M1). PURE: plain rows in,
 * numbers out, so every definition is provable in a harness and the dashboard (a later milestone)
 * can only ever show what these definitions say.
 *
 * Row shape:
 *   stage            current pipeline stage
 *   reached          string[]  every stage the enquiry has ever been in
 *   last_customer_at int|null  unix time of the customer's last message
 *   human_touched    bool      a member of staff or the owner wrote in the chat after the enquiry opened
 *   handover         bool      a takeover was recorded on the enquiry
 *   flagged          int       staff "incorrect AI answer" flags
 *   guard_hits       int       times a guard had to correct the model (invented fare, passport request,
 *                              broken marker, a detail the validator refused)
 *
 * "Incorrect responses" counts only what a person or a guard noticed. It is a floor, not an error rate.
 */
final class EnquiryMetrics
{
    /** The stage an AI-only conversation is expected to reach in this milestone. */
    public const AI_TARGET = TravelPipeline::DETAILS_COLLECTED;

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,int|float|null>
     */
    public static function compute(array $rows, int $now, int $abandonHours = 48): array
    {
        $total = count($rows);
        $open = 0; $ready = 0; $abandoned = 0; $handover = 0; $successfulAi = 0; $flagged = 0; $guardHits = 0;
        $reachedDetails = 0; $reachedQuote = 0; $reachedTicket = 0;

        foreach ($rows as $r) {
            $stage   = (string) ($r['stage'] ?? '');
            $reached = array_unique(array_merge((array) ($r['reached'] ?? []), [$stage]));
            $human   = ! empty($r['human_touched']);
            $handed  = ! empty($r['handover']);
            $flags   = (int) ($r['flagged'] ?? 0);

            if (TravelPipeline::isOpen($stage)) {
                $open++;
            }
            if ($stage === TravelPipeline::DETAILS_COLLECTED) {
                $ready++;
            }
            if (self::isAbandoned($stage, $r['last_customer_at'] ?? null, $now, $abandonHours)) {
                $abandoned++;
            }
            if ($handed || $human) {
                $handover++;
            }
            if (in_array(self::AI_TARGET, $reached, true) && ! $human && ! $handed && $flags === 0) {
                $successfulAi++;
            }
            $flagged   += $flags;
            $guardHits += (int) ($r['guard_hits'] ?? 0);

            if (in_array(TravelPipeline::DETAILS_COLLECTED, $reached, true)) {
                $reachedDetails++;
            }
            if (in_array(TravelPipeline::QUOTATION_SENT, $reached, true)) {
                $reachedQuote++;
            }
            if (in_array(TravelPipeline::TICKET_ISSUED, $reached, true)) {
                $reachedTicket++;
            }
        }

        return [
            'total'                  => $total,
            'open'                   => $open,
            'ready_to_price'         => $ready,
            'abandoned'              => $abandoned,
            'successful_ai'          => $successfulAi,
            'successful_ai_pct'      => self::pct($successfulAi, $total),
            'human_handover'         => $handover,
            'human_handover_pct'     => self::pct($handover, $total),
            'flagged_answers'        => $flagged,
            'guard_hits'             => $guardHits,
            'incorrect_responses'    => $flagged + $guardHits,
            'enquiry_to_quotation'   => self::pct($reachedQuote, $reachedDetails),
            'quotation_to_ticket'    => self::pct($reachedTicket, $reachedQuote),
        ];
    }

    /**
     * Abandoned: still open, not yet quoted, and the customer has been silent longer than the limit.
     * An enquiry a person already closed, or one that has a quotation out, is not abandoned.
     */
    public static function isAbandoned(string $stage, ?int $lastCustomerAt, int $now, int $abandonHours = 48): bool
    {
        if (! in_array($stage, [TravelPipeline::NEW_ENQUIRY, TravelPipeline::DETAILS_COLLECTED, TravelPipeline::FLIGHT_SEARCHED], true)) {
            return false;
        }
        if ($lastCustomerAt === null) {
            return false;
        }

        return ($now - $lastCustomerAt) > max(1, $abandonHours) * 3600;
    }

    /** Whole-number percentage, or null when there is nothing to divide by (never a made-up 0%). */
    private static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }
}
