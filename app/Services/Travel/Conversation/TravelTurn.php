<?php

namespace App\Services\Travel\Conversation;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Insight\CustomerTripHistory;
use App\Support\BotTrace;
use Illuminate\Support\Facades\Log;

/**
 * The Travel Vertical's single doorway into the AI brain (Travel Vertical · M1).
 *
 * AiBrain calls three things here, each behind `EnquiryService::isTravel($tenant)`:
 *   promptBlock()   the consultant rules, once, in the cacheable part of the system prompt
 *   contextBlock()  what we already know about THIS customer's trip, every turn
 *   afterReply()    read the model's hidden <<TRIP>> line, store the change, guard the wording
 *
 * The model talks; this class decides. It never lets the model's text set a fare, ask for passport
 * data, or claim a detail was saved that the validator refused.
 */
final class TravelTurn
{
    /** Changing one of these re-sends the trip to the team. */
    private const CORE = ['origin', 'destination', 'depart_date', 'return_date', 'trip_type', 'segments', 'adults', 'children', 'infants'];

    private const NEEDED = [
        'destination' => 'where they are flying to',
        'origin'      => 'where they are flying from',
        'depart_date' => 'the travel date',
        'trip_type'   => 'whether it is one way or return',
        'return_date' => 'the return date',
        'passengers'  => 'how many adults, children (2–11) and infants (under 2)',
        'segments'    => 'the list of flights (from, to and date for each)',
    ];

    public function __construct(private EnquiryService $enquiries) {}

    /** The fixed consultant rules for a travel tenant. Depends only on tenant settings. */
    public static function promptBlock(Tenant $tenant): string
    {
        $home   = trim((string) $tenant->setting('travel_home_airport', ''));
        $svc    = trim((string) $tenant->setting('travel_services', ''));
        $terms  = trim((string) $tenant->setting('travel_booking_terms', ''));
        $cancel = trim((string) $tenant->setting('travel_cancellation_policy', ''));

        $p  = "YOU ARE A TRAVEL CONSULTANT (this business is a travel agency — help like an experienced consultant, never like a call centre or a system).\n";
        $p .= "FLIGHT ENQUIRIES:\n";
        $p .= "- Find out, one or two SHORT questions at a time: where from, where to, the travel date, one way or return (and the return date), and how many adults, children (2–11) and infants (under 2). Cabin, preferred airline, baggage, flexible dates and direct-only are OPTIONAL: note them when the customer offers them, do not interrogate.\n";
        if ($home !== '') {
            $p .= "- Most of our customers fly from {$home}. If the customer has not said where they fly from, ASK, offering {$home} as the likely answer — never assume it.\n";
        }
        $p .= "- TRIP SO FAR (further down) is our stored record of this customer's trip. It is the truth. Never ask again for anything it already holds; ask only for what STILL NEEDED lists.\n";
        $p .= "- CHANGES: the customer may change any detail at any time (\"make it the 22nd\", \"3 adults not 2\", \"Nairobi instead\", \"business class\"). Change ONLY that detail, keep every other detail exactly as recorded, and confirm just the change in one short line. NEVER restart the questions and never ask them to repeat the trip.\n";
        $p .= "- TRIP RECORD (a hidden line the customer never sees): whenever the customer's latest message states or changes ANY flight detail, end your reply with ONE line in exactly this form, containing ONLY the fields that are new or changed in that message:\n";
        $p .= "  <<TRIP {\"from\":\"Entebbe\",\"to\":\"Dubai\",\"trip_type\":\"return\",\"depart_date\":\"2026-12-20\",\"return_date\":\"2027-01-04\",\"adults\":2,\"children\":1,\"infants\":0,\"cabin\":\"economy\",\"airlines\":[\"Emirates\"],\"baggage\":\"2 bags each\",\"flexible_days\":2,\"direct_only\":true,\"notes\":\"wheelchair assistance\"}>>\n";
        $p .= "  Rules for that line: write places as the customer said them (city or airport name; add the country if they gave it). Dates MUST be YYYY-MM-DD — work them out from TODAY; a date with no year means its next occurrence. trip_type is one_way, return or multi_city. Passenger numbers are the NEW TOTALS after the change. Never include a value the customer did not give, and never repeat unchanged fields.\n";
        $p .= "  Multi-city: {\"trip_type\":\"multi_city\",\"segments\":[{\"from\":\"Entebbe\",\"to\":\"Nairobi\",\"date\":\"2026-12-01\"},{\"from\":\"Nairobi\",\"to\":\"Dubai\",\"date\":\"2026-12-05\"}]}. To change one flight: {\"segment\":{\"index\":2,\"to\":\"Doha\"}}. To remove an optional detail: {\"clear\":[\"return_date\"]}. If the customer clearly starts a DIFFERENT trip or asks to start over, add \"new_trip\":true.\n";
        $p .= "  The system checks and stores the line and adds the trip summary to your message ITSELF. So do NOT write your own recap list of the trip, and do not say a detail is saved unless you put it in the line.\n";
        $p .= "- NEVER STATE A FARE. You do not know flight prices: never state, estimate, or say 'from' an amount for a flight, and never promise a seat is available. Fares are confirmed by our team in a written quotation. If asked the price before the record is complete, say you will get the best fares as soon as you have the details, and ask the next missing detail.\n";
        $p .= "- WHEN NOTHING IS STILL NEEDED: tell the customer our travel team is checking the best fares and will send a quotation. Do not promise a time you were not given.\n";
        $p .= "- PASSPORTS AND PHOTOS: NEVER ask for a passport number, a passport photo or any identity document in this chat, and tell the customer not to send them — our team collects them securely at booking. You cannot open photos here; if the customer sends one, ask them to type the details instead. For a quotation you only need the trip details above.\n";
        $p .= "- A PERSON, NOT YOU: a group of 10 or more travellers, a child travelling alone, medical or special-assistance needs, a complaint, a payment problem, or a change or refund on an existing ticket — tell the customer a member of our team will assist them personally, and do not try to resolve it yourself.\n";
        $p .= "OTHER TRAVEL SERVICES (safaris, tours, holiday packages, hotels, visas, transfers, car hire, insurance)" . ($svc !== '' ? " — we offer: {$svc}" : '') . ":\n";
        $p .= "- These are PRICED PER ENQUIRY. Discover the essentials in one or two short questions (destination, dates or month, number of travellers, style or budget if offered), repeat them back, tell the customer our team will prepare a tailored quotation, and append the hidden follow-up line so the team is alerted, e.g. <<FOLLOWUP safari for 4 to Bwindi in July | enquiry details>>. Do NOT use the TRIP line for these.\n";
        $p .= "- NEVER INVENT A PRICE for a safari, tour, package or hotel. Only an item that carries a fixed fee in PRODUCTS (for example a visa service or an airport transfer) may be quoted, at that fee. Never emit <<QUOTE>> for a trip that has no price in PRODUCTS.\n";
        $p .= "- BE A CONSULTANT, NOT A PITCH: suggest at most ONE relevant add-on when it genuinely helps (an airport transfer with a flight, travel insurance with an international trip). Answer destination, season and what-to-expect questions helpfully and honestly from what you know; if unsure, say the team will confirm.\n";
        $p .= "- BOOKING & PAYMENT: when the customer wants to proceed, explain our terms from the lines below and COMPANY KNOWLEDGE — never invent an amount or a deadline; the team confirms the quotation and the payment instructions.\n";
        if ($terms !== '') {
            $p .= "  Booking terms: " . mb_substr(preg_replace('/\s+/', ' ', $terms) ?? '', 0, 600) . "\n";
        }
        if ($cancel !== '') {
            $p .= "  Changes and cancellations: " . mb_substr(preg_replace('/\s+/', ' ', $cancel) ?? '', 0, 600) . "\n";
        }

        return $p;
    }

    /** What we know about this customer's trip right now. Changes every turn, so it rides outside the cached prefix. */
    public function contextBlock(Tenant $tenant, Conversation $convo, string $from, string $channel): string
    {
        try {
            $tz  = $this->enquiries->timezone($tenant);
            $now = now($tz);
            $b   = "\nTODAY: " . $now->format('Y-m-d') . ' (' . $now->format('l') . "). Use it to turn what the customer says into YYYY-MM-DD dates.\n";

            $enquiry = $this->enquiries->current($tenant, $from, $channel);
            if (! $enquiry) {
                $b .= "TRIP SO FAR: nothing recorded yet for this customer.\n";

                return $b . CustomerTripHistory::block($tenant, $from, null);
            }
            $state = $enquiry->state();
            $lines = EnquirySlots::summaryLines($state);
            $b .= "TRIP SO FAR (our stored record — the truth; never ask again for anything listed here):\n";
            $b .= $lines ? '- ' . implode("\n- ", $lines) . "\n" : "- (no details stored yet)\n";
            $missing = EnquirySlots::missing($state);
            $b .= 'STILL NEEDED: ' . ($missing ? implode('; ', array_map([self::class, 'neededLabel'], $missing)) . '. Ask for at most two of these in one message.'
                : 'nothing — the record is complete. Do not ask for more; our team is preparing the fares.') . "\n";
            foreach ($state['pending'] as $q) {
                if (($q['kind'] ?? '') === 'ambiguous_place') {
                    $b .= 'OPEN QUESTION — the customer said "' . ($q['input'] ?? '') . '" and we need to know which one: ' . implode(' / ', (array) ($q['options'] ?? []))
                        . ". When they answer, put the full name (city and country) in the TRIP line.\n";
                }
            }

            return $b . CustomerTripHistory::block($tenant, $from, (int) $enquiry->id);
        } catch (\Throwable $e) {
            Log::warning('travel: context block failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * After the model has replied: store what it reported, then make its wording safe.
     * Always returns text with no <<TRIP>> marker in it, even when something goes wrong.
     */
    public function afterReply(Tenant $tenant, Conversation $convo, string $from, string $channel, string $customerText, string $reply): string
    {
        $marker = TripMarker::extract($reply);          // pure: the marker is gone whatever happens next
        $clean  = $marker['reply'];

        try {
            $enquiry = $this->enquiries->current($tenant, $from, $channel);
            $append  = [];

            if ($marker['patch'] !== null || $marker['new_trip']) {
                $state = is_array($convo->state) ? $convo->state : [];
                $res = $this->enquiries->applyPatch(
                    $tenant, $from, $channel, $marker['patch'] ?? [], $marker['new_trip'], $convo->id ? (int) $convo->id : null, $customerText,
                    $channel === 'web' ? (trim((string) ($state['contact_name'] ?? '')) ?: null) : null,
                    $channel === 'web' ? (string) ($state['contact_phone'] ?? '') : $from,
                );
                $enquiry = $res['enquiry'];

                foreach (array_slice(self::issueLines($res['issues']), 0, 2) as $line) {
                    $append[] = $line;
                }
                $coreChanged = (bool) array_intersect(self::CORE, $res['changed']);
                if ($res['complete'] && ($res['became_complete'] || $coreChanged)) {
                    $append[] = self::summaryForCustomer($enquiry->state(), ! $res['became_complete']);
                    $this->enquiries->announceReady($tenant, $enquiry, ! $res['became_complete']);
                }
            } elseif ($enquiry) {
                $enquiry->last_customer_at = now();
                $enquiry->save();
            }

            if ($marker['malformed']) {
                $enquiry ? $this->enquiries->guardHit($enquiry, 'marker_malformed') : BotTrace::log($tenant->id, 'travel', $from, 'marker_malformed', null);
            }

            // Guard the model's own words. Real catalogue prices and the customer's own figures are allowed.
            $g = ReplyGuard::clean($clean, array_merge($this->cataloguePrices($tenant), ReplyGuard::amounts($customerText)));
            $clean = $g['reply'];
            foreach (['fare_stripped' => $g['fare_hits'], 'passport_request_stripped' => $g['passport_hits']] as $guard => $n) {
                if ($n > 0) {
                    $enquiry ? $this->enquiries->guardHit($enquiry, $guard, $n) : BotTrace::log($tenant->id, 'travel', $from, $guard, (string) $n);
                }
            }

            if ($append) {
                $clean = trim($clean . "\n\n" . implode("\n\n", $append));
            }
        } catch (\Throwable $e) {
            // Never lose the reply over bookkeeping — but never let the marker out either.
            Log::warning('travel: afterReply failed: ' . $e->getMessage());
        }

        return $clean;
    }

    /** The trip as the customer sees it, built by code from the stored record. */
    public static function summaryForCustomer(array $state, bool $updated = false): string
    {
        $out = ($updated ? "✈️ *Updated trip request*\n" : "✈️ *Your trip request*\n") . implode("\n", EnquirySlots::summaryLines($state));
        if (EnquirySlots::isGroup($state)) {
            return $out . "\n\nAs this is a group booking, a member of our team will assist you personally.";
        }

        return $out . "\n\n" . ($updated ? 'Our travel team will use these details for your quotation.'
            : 'Our travel team is checking the best fares and will send your quotation shortly.');
    }

    /**
     * What to tell the customer when the validator refused something the model reported.
     *
     * @param  array<int,array{code:string,field:string,detail:string}> $issues
     * @return string[]
     */
    public static function issueLines(array $issues): array
    {
        $out = [];
        foreach ($issues as $i) {
            $d = (string) ($i['detail'] ?? '');
            $from = ($i['field'] ?? '') === 'origin';
            $line = match ($i['code']) {
                'date_past'               => 'That date (' . (EnquirySlots::niceDate($d) ?: $d) . ') has already passed. Which date would you like?',
                'date_far'                => 'I can only take trips up to a year ahead. Which date would you like?',
                'bad_date'                => "I didn't catch that date. Could you send it as day and month, for example 20 December?",
                'return_before_departure' => 'The return date needs to be after the departure date. Which return date would you like?',
                'segment_order'           => 'Flight ' . preg_replace('/\D/', '', (string) $i['field']) . ' is dated before the flight ahead of it. Which date should it be?',
                'same_place'              => "The departure and destination look like the same place ({$d}). Where are you flying to?",
                'ambiguous_place'         => 'Which one do you mean: ' . implode(' or ', explode(' | ', $d)) . '?',
                'unknown_place'           => "I couldn't find \"{$d}\" as a city or airport. Which city is it?",
                'need_city'               => "Which city in {$d} are you flying " . ($from ? 'from' : 'to') . '?',
                'infant_ratio'            => 'Each infant travels with their own adult, so I can take at most one infant per adult. How many adults and infants will travel?',
                'minor_alone'             => "Children can't be booked to travel on their own in this chat. Will an adult travel with them? A member of our team can also advise on children travelling alone.",
                'no_adult'                => 'At least one adult must travel. How many adults will there be?',
                'bad_segment'             => "I couldn't tell which flight to change. Which flight number is it?",
                default                   => null,     // group_booking is covered in the trip summary
            };
            if ($line !== null && ! in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    public static function neededLabel(string $key): string
    {
        if (preg_match('/^segment_(\d+)$/', $key, $m)) {
            return 'flight ' . $m[1] . ' (from, to and date)';
        }

        return self::NEEDED[$key] ?? $key;
    }

    /** @return float[] real prices in this agency's catalogue (fixed-fee services) */
    private function cataloguePrices(Tenant $tenant): array
    {
        try {
            return Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('price', '>', 0)
                ->limit(500)->pluck('price')->map(fn ($p) => (float) $p)->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
