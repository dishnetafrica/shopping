<?php

namespace App\Services\Travel\Insight;

use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Enquiry\TravelPipeline;

/**
 * Returning-customer recognition (Travel Vertical · M1). Summarises a customer's EARLIER enquiries
 * with the same agency so the consultant can say "Travelling from Entebbe again?".
 *
 * Privacy rules, enforced here:
 *   - same tenant and same phone only — history never crosses agencies;
 *   - trip facts only (route, month, party size, cabin, outcome). Nothing is inferred about why
 *     someone travels;
 *   - a per-tenant switch (`travel_use_history`, on by default) turns it off entirely;
 *   - the block tells the model to OFFER the earlier details, never to assume them.
 *
 * Computed from stored travel records, not guessed by the AI.
 */
final class CustomerTripHistory
{
    public const LIMIT = 3;

    public static function enabled(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('travel_use_history', true);
    }

    /** @return array<int,array{route:string,when:string,party:string,cabin:string,outcome:string}> */
    public static function trips(Tenant $tenant, string $phone, ?int $excludeId = null): array
    {
        if (! self::enabled($tenant) || $phone === '') {
            return [];
        }
        $rows = TravelEnquiry::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $phone)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->whereNotNull('destination')
            ->latest('id')->limit(self::LIMIT)->get();

        $out = [];
        foreach ($rows as $e) {
            $state = $e->state();
            $out[] = [
                'route'   => EnquirySlots::route($state),
                'when'    => $e->depart_date ? $e->depart_date->format('M Y') : '',
                'party'   => EnquirySlots::paxLine($state),
                'cabin'   => EnquirySlots::cabinLabel($e->cabin),
                'outcome' => in_array($e->stage, [TravelPipeline::TICKET_ISSUED, TravelPipeline::COMPLETED], true) ? 'booked' : 'enquired',
            ];
        }

        return $out;
    }

    /** The prompt block. Empty when there is no history or the switch is off. */
    public static function block(Tenant $tenant, string $phone, ?int $excludeId = null): string
    {
        $trips = self::trips($tenant, $phone, $excludeId);
        if (! $trips) {
            return '';
        }
        $b = "\nRETURNING CUSTOMER — earlier trips this customer asked us about (facts from our records):\n";
        foreach ($trips as $t) {
            $b .= '- ' . implode(' · ', array_filter([$t['route'], $t['when'], $t['party'], $t['cabin'], $t['outcome']])) . "\n";
        }

        return $b . "Welcome them back warmly. You may OFFER a detail from an earlier trip as a question (\"Flying from Entebbe again?\") "
            . "but never assume it and never put it in the TRIP record until the customer confirms it in this conversation.\n";
    }
}
