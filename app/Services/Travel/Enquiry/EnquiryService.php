<?php

namespace App\Services\Travel\Enquiry;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Services\Bot\LeadService;
use App\Support\Vertical;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one writer of travel enquiries (Travel Vertical · M1). Everything that creates, edits, moves
 * or closes an enquiry goes through here, so the pipeline rules, the timeline and the lead link are
 * applied in exactly one place.
 *
 * Every query names the tenant explicitly as well as relying on the model's tenant scope, so the
 * service behaves the same from a queued job, a web request or a console command.
 */
final class EnquiryService
{
    private AirportDirectory $airports;

    public function __construct()
    {
        // One shared directory per process: the container must not build a fresh 4,000-row index
        // for every service instance.
        $this->airports = AirportDirectory::shared();
    }

    public static function isTravel(?Tenant $tenant): bool
    {
        return $tenant !== null && Vertical::of($tenant) === Vertical::TRAVEL;
    }

    /** The customer's open enquiry on this channel, if any. */
    public function current(Tenant $tenant, string $phone, string $channel = 'whatsapp'): ?TravelEnquiry
    {
        return TravelEnquiry::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $phone)
            ->where('channel', $channel)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();
    }

    /**
     * Apply what the customer just said. Creates the enquiry on the first trip detail.
     *
     * @param  array   $patch      fields reported by the model (TripMarker)
     * @param  ?string $leadPhone  the customer's real phone number for the lead; null when unknown
     *                             (website chat identifies a visitor by a session id, not a number)
     * @return array{enquiry:TravelEnquiry,created:bool,changed:string[],issues:array,complete:bool,became_complete:bool,stage_changed:bool}
     */
    public function applyPatch(Tenant $tenant, string $phone, string $channel, array $patch, bool $newTrip = false, ?int $conversationId = null, string $customerText = '', ?string $customerName = null, ?string $leadPhone = null): array
    {
        return DB::transaction(function () use ($tenant, $phone, $channel, $patch, $newTrip, $conversationId, $customerText, $customerName, $leadPhone) {
            $enquiry = $this->current($tenant, $phone, $channel);

            // "Actually, a different trip": close the old one as it stands, never overwrite it.
            if ($enquiry && $newTrip) {
                $this->changeStage($enquiry, TravelPipeline::CANCELLED, 'customer', null, 'Customer started a different trip');
                $enquiry = null;
            }

            $created = false;
            if (! $enquiry) {
                $enquiry = new TravelEnquiry([
                    'tenant_id'       => $tenant->id,
                    'conversation_id' => $conversationId,
                    'customer_phone'  => $phone,
                    'customer_name'   => $customerName ? mb_substr($customerName, 0, 120) : null,
                    'channel'         => $channel,
                    'stage'           => TravelPipeline::NEW_ENQUIRY,
                ]);
                $enquiry->fillState(EnquirySlots::blank());
                $created = true;
            }

            $wasComplete = ! $created && EnquirySlots::isComplete($enquiry->state());
            $today  = now($this->timezone($tenant))->format('Y-m-d');
            $result = EnquirySlots::apply($enquiry->state(), $patch, $today, fn (string $q) => $this->airports->resolve($q));

            $enquiry->fillState($result['state']);
            $enquiry->last_customer_at = now();
            $enquiry->save();

            if ($created) {
                $this->log($enquiry, 'created', 'customer', ['channel' => $channel]);
                $this->linkLead($tenant, $enquiry, $customerText, $leadPhone);
            }
            if ($result['changed']) {
                $this->log($enquiry, 'details_changed', 'customer', ['fields' => $result['changed']]);
            }
            foreach ($result['issues'] as $i) {
                $this->log($enquiry, 'validator_reject', 'system', ['code' => $i['code'], 'field' => $i['field']]);
            }

            $complete = EnquirySlots::isComplete($result['state']);
            $stageChanged = false;
            $target = TravelPipeline::fromDetails((string) $enquiry->stage, $complete);
            if ($target !== null) {
                $stageChanged = $this->changeStage($enquiry, $target, 'system', null, $complete ? 'All trip details supplied' : 'A change left a detail missing');
            }
            $becameComplete = $complete && ! $wasComplete;
            if ($becameComplete && $enquiry->details_completed_at === null) {
                $enquiry->details_completed_at = now();
                $enquiry->save();
            }

            return [
                'enquiry' => $enquiry, 'created' => $created, 'changed' => $result['changed'], 'issues' => $result['issues'],
                'complete' => $complete, 'became_complete' => $becameComplete, 'stage_changed' => $stageChanged,
            ];
        });
    }

    /** Move an enquiry along the pipeline. Refuses any move the pipeline does not allow. */
    public function changeStage(TravelEnquiry $enquiry, string $to, string $actor = 'staff', ?int $userId = null, ?string $reason = null): bool
    {
        $from = (string) $enquiry->stage;
        if (! TravelPipeline::canMove($from, $to)) {
            return false;
        }
        $enquiry->stage = $to;
        $enquiry->closed_at = TravelPipeline::isOpen($to) ? null : now();
        if ($to === TravelPipeline::LOST || $to === TravelPipeline::CANCELLED) {
            $enquiry->lost_reason = $reason !== null ? mb_substr($reason, 0, 250) : $enquiry->lost_reason;
        }
        $enquiry->save();
        $this->log($enquiry, 'stage_changed', $actor, array_filter(['from' => $from, 'to' => $to, 'reason' => $reason ? mb_substr($reason, 0, 250) : null]), $userId);

        // Keep the generic lead in step, coarsely: lost stays lost, a ticket is a win.
        try {
            if ($enquiry->lead_id && in_array($to, [TravelPipeline::LOST, TravelPipeline::TICKET_ISSUED], true)) {
                $lead = Lead::withoutGlobalScopes()->where('tenant_id', $enquiry->tenant_id)->find($enquiry->lead_id);
                if ($lead) {
                    $to === TravelPipeline::LOST ? app(LeadService::class)->markLost($lead) : app(LeadService::class)->markWon($lead);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('travel: lead sync failed: ' . $e->getMessage());
        }

        return true;
    }

    public function assignOwner(TravelEnquiry $enquiry, ?int $ownerUserId, ?int $byUserId = null): void
    {
        $enquiry->owner_user_id = $ownerUserId;
        $enquiry->save();
        $this->log($enquiry, 'owner_assigned', 'staff', ['owner_user_id' => $ownerUserId], $byUserId);
    }

    public function addNote(TravelEnquiry $enquiry, string $note, ?int $byUserId = null): void
    {
        $this->log($enquiry, 'note', 'staff', ['note' => mb_substr(trim($note), 0, 500)], $byUserId);
    }

    /** A member of staff marks an AI answer as wrong. Counted by the AI-quality measures. */
    public function flagAi(TravelEnquiry $enquiry, string $category, string $note = '', ?int $byUserId = null): void
    {
        $allowed = ['wrong_detail', 'wrong_information', 'invented_price', 'other'];
        $this->log($enquiry, 'ai_flagged', 'staff', array_filter([
            'category' => in_array($category, $allowed, true) ? $category : 'other',
            'note'     => mb_substr(trim($note), 0, 500) ?: null,
        ]), $byUserId);
    }

    /** A guard removed something from the model's reply (an invented fare, a passport request…). */
    public function guardHit(TravelEnquiry $enquiry, string $guard, int $count = 1): void
    {
        $this->log($enquiry, 'guard_hit', 'system', ['guard' => $guard, 'count' => $count]);
    }

    /**
     * Staff took over a chat or handed it back. Records it on the customer's open trip, with the
     * reason. A no-op for tenants that are not travel agencies and for chats with no open trip.
     */
    public function recordHandover(?Tenant $tenant, string $phone, bool $started, string $reason = '', ?int $byUserId = null): ?TravelEnquiryEvent
    {
        if (! self::isTravel($tenant)) {
            return null;
        }
        $enquiry = TravelEnquiry::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('customer_phone', $phone)->whereNull('closed_at')
            ->latest('id')->first();
        if (! $enquiry) {
            return null;
        }

        return $this->log($enquiry, $started ? 'handover_started' : 'handover_released', 'staff',
            array_filter(['reason' => mb_substr(trim($reason), 0, 250) ?: null]), $byUserId);
    }

    public function log(TravelEnquiry $enquiry, string $type, string $actor = 'system', array $meta = [], ?int $userId = null): TravelEnquiryEvent
    {
        return TravelEnquiryEvent::create([
            'tenant_id'     => $enquiry->tenant_id,
            'enquiry_id'    => $enquiry->id,
            'type'          => $type,
            'actor'         => $actor,
            'actor_user_id' => $userId,
            'meta'          => $meta ?: null,
            'created_at'    => now(),
        ]);
    }

    /**
     * The customer confirmed the summary: this is the one submission of the enquiry. Hands it to the
     * agency's staff and reports honestly whether anyone was told. Calling it again for an enquiry
     * already submitted sends only what has changed, to people who have not had that content.
     *
     * @return array{first:bool,recipients:int,sent:int,failed:int,no_recipient:bool,already:int}
     */
    public function submit(Tenant $tenant, TravelEnquiry $enquiry): array
    {
        $first = $enquiry->submitted_at === null;
        if ($first) {
            $enquiry->submitted_at = now();
            $enquiry->save();
            $this->log($enquiry, 'submitted', 'customer', ['reference' => $enquiry->reference()]);
        }
        $this->announceReady($tenant, $enquiry, ! $first);

        return ['first' => $first] + app(\App\Services\Travel\Handoff\StaffHandoff::class)->notify($tenant, $enquiry, $first ? 'new' : 'updated');
    }

    /** The customer has been shown the summary and asked to confirm it. */
    public function markSummaryShown(TravelEnquiry $enquiry): void
    {
        $enquiry->summary_shown_at = now();
        $enquiry->save();
    }

    /** Put the trip on the linked lead, so the lead list shows what the enquiry is about. Best-effort. */
    public function announceReady(Tenant $tenant, TravelEnquiry $enquiry, bool $updated = false): void
    {
        $lines = EnquirySlots::summaryLines($enquiry->state());
        try {
            if ($enquiry->lead_id) {
                Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('id', $enquiry->lead_id)
                    ->update(['interest' => mb_substr('Travel: ' . implode(' | ', array_slice($lines, 0, 3)), 0, 200)]);
            }
        } catch (\Throwable $e) {
            Log::warning('travel: lead update failed: ' . $e->getMessage());
        }
    }

    public function timezone(Tenant $tenant): string
    {
        $tz = (string) $tenant->setting('timezone', '');

        return $tz !== '' && in_array($tz, timezone_identifiers_list(), true) ? $tz : (string) config('app.timezone', 'UTC');
    }

    /** Every enquiry is a lead: reuse LeadService (its dedupe, scoring and assignment) and link the row. */
    private function linkLead(Tenant $tenant, TravelEnquiry $enquiry, string $customerText, ?string $leadPhone): void
    {
        try {
            $digits = preg_replace('/[^0-9]/', '', (string) $leadPhone) ?? '';
            if (strlen($digits) < 7) {
                return;                                    // no real number known (anonymous website visitor)
            }
            $text = trim($customerText) !== '' ? mb_substr(trim($customerText), 0, 300) : 'New travel enquiry';
            app(LeadService::class)->capture($tenant, $digits, $enquiry->customer_name, 'Travel enquiry: ' . $text,
                'lead', $enquiry->channel === 'web' ? 'web' : 'whatsapp', $enquiry->conversation_id);
            $lead = Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_phone', $digits)
                ->whereIn('status', Lead::OPEN_STATUSES)->latest('id')->first();
            if ($lead) {
                $enquiry->lead_id = $lead->id;
                $enquiry->save();
            }
        } catch (\Throwable $e) {
            Log::warning('travel: lead capture failed: ' . $e->getMessage());   // a lead hiccup must not lose the enquiry
        }
    }
}
