<?php

namespace App\Services\Travel\Conversation;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Travel\Enquiry\AirportDirectory;
use App\Services\Travel\Enquiry\EnquiryBrief;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Insight\CustomerTripHistory;
use App\Services\Travel\Quality\TripDetailSignal;
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
    private const CORE = ['origin', 'destination', 'depart_date', 'return_date', 'trip_type', 'segments', 'adults', 'children', 'infants',
        'services', 'destinations', 'start_date', 'end_date', 'date_text', 'child_ages'];

    public const ASK_TO_CONFIRM = 'Please check these details. Reply *YES* if they are correct and I will pass your request to our travel team — or tell me what to change.';

    private const NEEDED = [
        'destination' => 'where they are flying to',
        'origin'      => 'where they are flying from',
        'depart_date' => 'the travel date',
        'trip_type'   => 'whether it is one way or return',
        'return_date' => 'the return date',
        'passengers'  => 'how many adults, children (2–11) and infants (under 2)',
        'segments'    => 'the list of flights (from, to and date for each)',
        'service'         => 'what they need (flights, a safari or tour, a hotel, a visa, a transfer, insurance…)',
        'destinations'    => 'where they want to go',
        'travel_dates'    => 'when they want to travel (exact dates, or the month if it is not fixed)',
        'child_ages'      => 'the age of each child',
        'request_details' => 'what exactly they need done',
    ];

    public function __construct(private EnquiryService $enquiries) {}

    /** The fixed consultant rules for a travel tenant. Depends only on tenant settings. */
    public static function promptBlock(Tenant $tenant): string
    {
        $home   = trim((string) $tenant->setting('travel_home_airport', ''));
        $svc    = trim((string) $tenant->setting('travel_services', ''));
        $terms  = trim((string) $tenant->setting('travel_booking_terms', ''));
        $cancel = trim((string) $tenant->setting('travel_cancellation_policy', ''));
        $facts  = trim((string) $tenant->setting('travel_profile_note', ''));

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
        $p .= "- WHEN NOTHING IS STILL NEEDED: do not ask for more. The system shows the summary and asks the customer to confirm it; after they confirm, our travel team prepares the quotation. Do not promise a time you were not given.\n";
        $p .= "- PASSPORTS AND PHOTOS: NEVER ask for a passport number, a passport photo or any identity document in this chat, and tell the customer not to send them — our team collects them securely at booking. You cannot open photos here; if the customer sends one, ask them to type the details instead. For a quotation you only need the trip details above.\n";
        $p .= "- A PERSON, NOT YOU: a group of 10 or more travellers, a child travelling alone, medical or special-assistance needs, a complaint, a payment problem, or a change or refund on an existing ticket — tell the customer a member of our team will assist them personally, and do not try to resolve it yourself.\n";
        $p .= "OTHER TRAVEL SERVICES (safaris, tours, holiday packages, hotels, visas, transfers, car hire, insurance)" . ($svc !== '' ? " — we offer: {$svc}" : '') . ":\n";
        $p .= "- These use the SAME hidden TRIP line, with these fields (only what is new or changed in the customer's latest message):\n";
        $p .= "  <<TRIP {\"services\":[\"safari\",\"hotel\"],\"destinations\":[\"Bwindi\",\"Queen Elizabeth National Park\"],\"departure\":\"Kampala\",\"start_date\":\"2027-07-12\",\"end_date\":\"2027-07-19\",\"date_text\":\"July 2027\",\"nights\":7,\"adults\":2,\"children\":2,\"child_ages\":[6,9],\"purpose\":\"family holiday\",\"accommodation\":\"mid-range lodges\",\"budget\":3000,\"budget_currency\":\"USD\",\"budget_per\":\"person\",\"contact_name\":\"Sarah\",\"contact_email\":\"sarah@example.com\",\"notes\":\"vegetarian meals\"}>>\n";
        $p .= "  services: any of flights, hotel, safari, tour, transfer, car_hire, visa, insurance, courier, ticket_change, other — name each one the customer asks for (it is added; use \"remove_services\" only if they drop one). destinations: the FULL list of places in visiting order, as the customer named them (a park, a town or a country is fine). Use start_date / end_date when the customer gives exact dates and date_text when they only know roughly (\"July\", \"Easter week\"). child_ages: the age of each child. budget is ONLY what the CUSTOMER says they can spend, with budget_currency if they said one — never a figure from you. Flights still use from / to / depart_date / return_date.\n";
        $p .= "- Discover the essentials one or two short questions at a time: what they need, where, when (or which month), how many adults and children and the children's ages. Purpose, accommodation style and budget are OPTIONAL — note them when offered, and you may ask about style or budget ONCE, never press.\n";
        $p .= "- NEVER INVENT A PRICE, A PACKAGE, AVAILABILITY, A DEPOSIT OR A DEADLINE for a flight, safari, tour, hotel, permit, transfer or visa. You do not know them. Our team prepares every quotation from confirmed supplier rates. Only a fee that appears in PRODUCTS (for example a fixed visa service fee) may be mentioned, exactly as listed.\n";
        $p .= "- THIS BUSINESS DOES NOT TAKE ORDERS FROM A PRODUCT LIST: never emit <<ORDER>>, <<QUOTE>> or <<ACCEPT_QUOTE>>, never build a cart, never send a price list. Everything goes on the TRIP line.\n";
        $p .= "- CONFIRMATION: when nothing is STILL NEEDED the system shows the customer a summary and asks them to confirm. When the customer then says it is correct, add \"confirm\":true to the TRIP line. Do NOT say the request has been sent or that the team has been told — the system adds that line itself, and only when it is true.\n";
        $p .= "- BE A CONSULTANT, NOT A PITCH: suggest at most ONE relevant add-on when it genuinely helps (an airport transfer with a flight, travel insurance with an international trip). Answer destination, season and what-to-expect questions helpfully and honestly from what you know; if unsure, say the team will confirm.\n";
        $p .= "- BOOKING & PAYMENT: when the customer wants to proceed, explain our terms from the lines below and COMPANY KNOWLEDGE — never invent an amount or a deadline; the team confirms the quotation and the payment instructions.\n";
        if ($facts !== '') {
            $p .= "ABOUT US (confirmed by the agency — state these as written, add nothing): " . mb_substr(preg_replace('/\s+/', ' ', $facts) ?? '', 0, 900) . "\n";
        }
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
                : 'nothing — the record is complete. Do not ask for more.') . "\n";
            if (! $missing) {
                $b .= $enquiry->isSubmitted()
                    ? 'STATUS: this request (' . $enquiry->reference() . ") has ALREADY been passed to our travel team. Do not ask the customer to confirm again. Changes are still welcome: report them on the TRIP line.\n"
                    : "STATUS: the customer has been shown the summary and asked to confirm. If they say it is correct, add \"confirm\":true to the TRIP line. If they change something, report the change instead.\n";
            }
            foreach (EnquiryBrief::softQuestions($state) as $soft) {
                if ($soft === 'budget_currency') {
                    $b .= "OPTIONAL: the customer gave a budget with no currency. Ask once which currency they mean.\n";
                }
            }
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

            // "confirm" is a signal about the conversation, not a trip detail.
            $confirm = false;
            if (is_array($marker['patch']) && array_key_exists('confirm', $marker['patch'])) {
                $confirm = filter_var($marker['patch']['confirm'], FILTER_VALIDATE_BOOLEAN);
                unset($marker['patch']['confirm']);
                if ($marker['patch'] === []) {
                    $marker['patch'] = null;
                }
            }

            $coreChanged = false;
            $becameComplete = false;
            if ($marker['patch'] !== null || $marker['new_trip']) {
                $state = is_array($convo->state) ? $convo->state : [];
                $patch = $marker['patch'] ?? [];
                // Website chat already collected a name and a number before the first message.
                if ($channel === 'web') {
                    foreach (['contact_name' => 'contact_name', 'contact_phone' => 'contact_phone'] as $key => $from_state) {
                        if (! array_key_exists($key, $patch) && trim((string) ($state[$from_state] ?? '')) !== '') {
                            $patch[$key] = (string) $state[$from_state];
                        }
                    }
                }
                $res = $this->enquiries->applyPatch(
                    $tenant, $from, $channel, $patch, $marker['new_trip'], $convo->id ? (int) $convo->id : null, $customerText,
                    $channel === 'web' ? (trim((string) ($state['contact_name'] ?? '')) ?: null) : null,
                    $channel === 'web' ? (string) ($state['contact_phone'] ?? '') : $from,
                );
                $enquiry = $res['enquiry'];

                foreach (array_slice(self::issueLines($res['issues']), 0, 2) as $line) {
                    $append[] = $line;
                }
                $coreChanged    = (bool) array_intersect(self::CORE, $res['changed']);
                $becameComplete = (bool) $res['became_complete'];
            } elseif ($enquiry) {
                $enquiry->last_customer_at = now();
                $enquiry->save();
            }

            // One submission per enquiry, and only after the customer has seen the summary built by
            // code and agreed to it. The model's "confirm" is accepted; so is a plain "yes" if the
            // model forgot to report it. A change in the same message is never a confirmation.
            if ($enquiry && EnquirySlots::isComplete($enquiry->state())) {
                if (! $enquiry->isSubmitted()) {
                    $agreed = ! $coreChanged && $enquiry->summary_shown_at !== null && ($confirm || Confirmation::isYes($customerText));
                    if ($agreed) {
                        $append[] = self::submittedLine($enquiry, $this->enquiries->submit($tenant, $enquiry));
                    } elseif ($becameComplete || $coreChanged || $enquiry->summary_shown_at === null) {
                        $append[] = self::summaryForCustomer($enquiry->state(), ! $becameComplete && $enquiry->summary_shown_at !== null);
                        $this->enquiries->markSummaryShown($enquiry);
                    }
                } elseif ($coreChanged) {
                    $result = $this->enquiries->submit($tenant, $enquiry);
                    $append[] = self::summaryForCustomer($enquiry->state(), true, false) . "\n\n"
                        . ($result['sent'] + $result['already'] > 0 ? 'Our travel team has been sent the updated details.' : 'The updated details are recorded for our travel team.');
                }
            }

            if ($marker['malformed']) {
                $enquiry ? $this->enquiries->guardHit($enquiry, 'marker_malformed') : BotTrace::log($tenant->id, 'travel', $from, 'marker_malformed', null);
            }

            // Measurement only — no extra model call, and no customer data. One trace row per turn with
            // two yes/no facts: did the model write its hidden trip line, and did the customer's message
            // contain a trip detail (fixed rules, TripDetailSignal). No phone number and none of the
            // customer's words are stored, so the row can be counted and shared freely.
            $wrote  = $marker['malformed'] ? 'malformed' : (($marker['patch'] !== null || $marker['new_trip']) ? 'yes' : 'no');
            $detail = TripDetailSignal::present($customerText, fn (string $q) => AirportDirectory::shared()->resolve($q)) ? 'yes' : 'no';
            BotTrace::log($tenant->id, 'travel', null, 'travel_turn', 'marker=' . $wrote . ' details=' . $detail);

            // Guard the model's own words. Real catalogue prices and the customer's own figures are allowed.
            $g = ReplyGuard::clean($clean, array_merge($this->cataloguePrices($tenant), ReplyGuard::amounts($customerText)));
            $clean = $g['reply'];
            foreach (['fare_stripped' => $g['fare_hits'], 'passport_request_stripped' => $g['passport_hits'], 'availability_stripped' => $g['availability_hits'] ?? 0] as $guard => $n) {
                if ($n > 0) {
                    $enquiry ? $this->enquiries->guardHit($enquiry, $guard, $n) : BotTrace::log($tenant->id, 'travel', $from, 'travel_guard', $guard . ' x' . $n);   // stage is 24 chars max
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

    /** The enquiry as the customer sees it, built by code from the stored record. */
    public static function summaryForCustomer(array $state, bool $updated = false, bool $ask = true): string
    {
        $out = ($updated ? "✈️ *Updated trip request*\n" : "✈️ *Your trip request*\n") . implode("\n", EnquirySlots::summaryLines($state));
        if (EnquirySlots::isGroup($state)) {
            $out .= "\n\nAs this is a group booking, a member of our team will assist you personally.";
        }

        return $ask ? $out . "\n\n" . self::ASK_TO_CONFIRM : $out;
    }

    /**
     * What the customer is told once they have confirmed. It says the team has been told ONLY when a
     * staff alert was actually delivered; otherwise it says, truthfully, that the request is recorded.
     *
     * @param array{sent:int,already:int} $result
     */
    public static function submittedLine(\App\Models\TravelEnquiry $enquiry, array $result): string
    {
        $told = ((int) ($result['sent'] ?? 0) + (int) ($result['already'] ?? 0)) > 0;

        return $told
            ? '✅ Thank you. Your request *' . $enquiry->reference() . '* has been passed to our travel team. They will prepare your quotation from confirmed rates and contact you.'
            : '✅ Thank you. Your request *' . $enquiry->reference() . '* is recorded. Our travel team will review it, prepare your quotation from confirmed rates and contact you.';
    }

    /** Remove retail markers a model might emit. A travel agency never runs the order or quotation pipeline. */
    public static function stripRetailMarkers(string $reply): string
    {
        foreach (['<<ORDER', '<<QUOTE', '<<ACCEPT_QUOTE'] as $m) {
            $pos = stripos($reply, $m);
            if ($pos !== false) {
                $end = strpos($reply, '>>', $pos);
                $reply = $end === false ? substr($reply, 0, $pos) : substr($reply, 0, $pos) . substr($reply, $end + 2);
            }
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $reply) ?? $reply);
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
                'end_before_start'        => 'The end date needs to be after the start date. When would you like the trip to end?',
                'bad_child_age'           => "I didn't catch one of the children's ages. How old is each child?",
                'child_ages_mismatch'     => 'The number of ages does not match the number of children. How many children are travelling, and how old is each?',
                'bad_currency'            => 'Which currency is that budget in — for example US dollars or Uganda shillings?',
                'bad_budget'              => "I didn't catch the budget figure. Roughly how much would you like to spend, and in which currency?",
                'bad_phone'               => "That phone number doesn't look complete. Could you send it with the country code?",
                'bad_email'               => "That email address doesn't look right. Could you check it?",
                'too_many_destinations'   => 'I can note up to eight places for one trip; our team can add more when they prepare your itinerary.',
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
