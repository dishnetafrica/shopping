<?php

namespace App\Services\Travel\Passport;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelTraveller;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Support\BotTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A travel customer sent a photo or PDF: read it as a passport or ID card, on this server, and put the
 * traveller on their trip. The single writer of travel_travellers.
 *
 *   1. The bytes come from PassportInbox (inline bytes the webhook set aside, encrypted), from the
 *      message itself (when the job got them inline), or from the WhatsApp provider by message key.
 *      They are only ever held in memory here.
 *   2. MrzReader reads the machine-readable zone with local OCR; every check digit must agree.
 *   3. The traveller is saved with the document number encrypted; the same document twice updates
 *      its row. The party (adults, children's ages) is filled only as Travellers::partyPatch allows.
 *   4. The customer is told what was read, so they can correct a misread name, and — when the trip is
 *      now complete — shown the summary to confirm, exactly as a typed message would.
 *
 * Nothing about the document goes to the AI model, the chat records or the logs, other than the
 * traveller's name and age on the trip summary.
 */
class PassportIntake
{
    public function __construct(private EnquiryService $enquiries, private MrzReader $reader) {}

    /**
     * @param  array  $media    PassportGuard::readable() output
     * @param  object $gateway  the tenant's WhatsApp gateway (for fetching by message key)
     * @return array{status:string,reply:?string,enquiry:?TravelEnquiry,traveller:?TravelTraveller}
     *         status: read | duplicate | unreadable | unsupported | too_large | no_file | unavailable | error
     */
    public function handle(Tenant $tenant, Conversation $convo, string $from, string $messageId, array $media, object $gateway): array
    {
        $none = fn (string $status) => ['status' => $status, 'reply' => null, 'enquiry' => null, 'traveller' => null];
        if (! $this->reader->ready()) {
            return $none('unavailable');
        }

        $b64 = $media['inline'] ?? null;
        if (($b64 === null || $b64 === '') && ! empty($media['stashed'])) {
            $b64 = PassportInbox::take((int) $tenant->id, $messageId);
        }
        if (($b64 === null || $b64 === '') && is_array($media['ref'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
            $b64 = $gateway->getMediaBase64((string) $tenant->whatsapp_instance, $media['ref']);
        }
        if (! is_string($b64) || $b64 === '') {
            return $none('no_file');
        }
        $bytes = base64_decode((string) preg_replace('/^data:[^,]*,/', '', $b64), true);
        unset($b64);
        if ($bytes === false || $bytes === '') {
            return $none('unsupported');
        }

        $today = now($this->enquiries->timezone($tenant))->format('Y-m-d');
        $read = $this->reader->read($bytes, (string) ($media['mime'] ?? ''), $today);
        unset($bytes);
        if ($read['status'] !== 'read' || $read['mrz'] === null) {
            return $none($read['status']);
        }

        try {
            [$enquiry, $traveller, $duplicate, $count] = $this->record($tenant, $convo, $from, $read['mrz'], $today);
        } catch (\Throwable $e) {
            Log::warning('travel: saving a traveller failed: ' . $e->getMessage());

            return $none('error');
        }

        return [
            'status'    => $duplicate ? 'duplicate' : 'read',
            'reply'     => $this->reply($tenant, $enquiry, $traveller, $duplicate, $count, $today),
            'enquiry'   => $enquiry,
            'traveller' => $traveller,
        ];
    }

    /** @return array{0:TravelEnquiry,1:TravelTraveller,2:bool,3:int} */
    private function record(Tenant $tenant, Conversation $convo, string $from, array $mrz, string $today): array
    {
        return DB::transaction(function () use ($tenant, $convo, $from, $mrz, $today) {
            $enquiry = $this->enquiries->current($tenant, $from, 'whatsapp');
            $state   = $enquiry ? $enquiry->state() : EnquirySlots::blank();
            $before  = $enquiry ? $enquiry->travellers()->get()->map(fn (TravelTraveller $t) => $t->facts())->all() : [];

            $fp = Travellers::fingerprint($mrz['document_type'], $mrz['issuing_country'], $mrz['document_number'], (string) config('app.key'));
            $facts = [
                'id' => 0, 'name' => Travellers::fullName($mrz['given_names'], $mrz['surname']), 'document_type' => $mrz['document_type'],
                'issuing_country' => $mrz['issuing_country'], 'nationality' => $mrz['nationality'], 'date_of_birth' => $mrz['date_of_birth'],
                'sex' => $mrz['sex'], 'expiry_date' => $mrz['expiry_date'], 'last4' => Travellers::last4($mrz['document_number']),
            ];
            $existing = $enquiry ? TravelTraveller::withoutGlobalScopes()->where('enquiry_id', $enquiry->id)->where('fingerprint', $fp)->first() : null;
            $after = $existing ? $before : array_merge($before, [$facts]);

            $on = Travellers::travelDate($state, $today);
            $patch = Travellers::partyPatch($state, $before, $after, $on);
            $res = $this->enquiries->applyPatch($tenant, $from, 'whatsapp', $patch, false, $convo->id ? (int) $convo->id : null, '', null, $from);
            $enquiry = $res['enquiry'];

            $traveller = TravelTraveller::withoutGlobalScopes()->updateOrCreate(
                ['enquiry_id' => $enquiry->id, 'fingerprint' => $fp],
                [
                    'tenant_id' => $tenant->id, 'source' => 'mrz', 'mrz_format' => $mrz['format'], 'document_type' => $mrz['document_type'],
                    'issuing_country' => $mrz['issuing_country'], 'surname' => mb_substr($mrz['surname'], 0, 80),
                    'given_names' => mb_substr($mrz['given_names'], 0, 120) ?: null, 'nationality' => $mrz['nationality'],
                    'date_of_birth' => $mrz['date_of_birth'], 'sex' => $mrz['sex'], 'expiry_date' => $mrz['expiry_date'],
                    'document_number' => $mrz['document_number'], 'document_last4' => Travellers::last4($mrz['document_number']),
                    'read_at' => now(),
                ],
            );
            // What happened, without the document: no name, no number, no date of birth in the timeline.
            $this->enquiries->log($enquiry, 'passport_read', 'customer', [
                'traveller_id' => (int) $traveller->id, 'document' => Travellers::documentLabel((string) $mrz['document_type']),
                'format' => $mrz['format'], 'duplicate' => $existing !== null, 'party_filled' => array_keys($patch),
            ]);

            return [$enquiry->fresh(), $traveller, $existing !== null, count($after)];
        });
    }

    private function reply(Tenant $tenant, TravelEnquiry $enquiry, TravelTraveller $traveller, bool $duplicate, int $count, string $today): string
    {
        $state = $enquiry->state();
        $on = Travellers::travelDate($state, $today);
        $facts = $traveller->facts();
        $lines = [Travellers::customerLine($facts, $on, Travellers::validity($facts, $today, $on, Travellers::returnDate($state)), $duplicate,
            Travellers::retentionDays($tenant->setting(TravellerRetention::SETTING, null)))];

        if (! $duplicate && Travellers::exceedsParty($state, $count)) {
            $lines[] = "I now have {$count} travel documents, but the trip is for " . EnquirySlots::paxLine($state) . '. Is someone else travelling?';
        }

        // The trip may now be complete: show the summary to confirm, exactly as after a typed message.
        if (EnquirySlots::isComplete($state)) {
            if (! $enquiry->isSubmitted()) {
                $lines[] = TravelTurn::summaryForCustomer($state, $enquiry->summary_shown_at !== null);
                $this->enquiries->markSummaryShown($enquiry);
            } elseif (! $duplicate) {
                $result = $this->enquiries->submit($tenant, $enquiry);
                $lines[] = $result['sent'] + $result['already'] > 0
                    ? 'Our travel team has been sent the updated traveller details.'
                    : 'The updated traveller details are recorded for our travel team.';
            }
        } elseif (! $duplicate) {
            $missing = EnquirySlots::missing($state);
            if ($missing) {
                $lines[] = 'To prepare your quotation I still need: ' . implode('; ', array_map([TravelTurn::class, 'neededLabel'], array_slice($missing, 0, 2))) . '.';
            }
        }
        BotTrace::log((int) $tenant->id, 'travel', null, 'travel_document', $duplicate ? 'duplicate' : 'read');

        return implode("\n\n", $lines);
    }
}
