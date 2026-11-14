<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\TimelineEvent;
use App\Capability\Dto\WorkspacePayload;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Models\TravelEnquiryNotification;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Enquiry\EnquiryBrief;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Enquiry\TravelPipeline;
use App\Services\Travel\Handoff\StaffHandoff;
use App\Services\Travel\Passport\Travellers;
use App\Services\Travel\Panel\TripBoard;

/**
 * A single travel enquiry (Travel Vertical · M1) — the V3 detail workspace: the trip as recorded,
 * what is still missing, its timeline, and the staff commands (change stage, mark lost, assign an
 * owner, add a note, flag an incorrect AI answer). Commands go through the CommandBus, and every
 * write goes through EnquiryService, which checks the pipeline and records the timeline.
 */
final class TravelEnquiryCapability implements ObjectCapability
{
    private const FLAG_LABELS = [
        'wrong_detail' => 'Wrong detail captured', 'wrong_information' => 'Wrong information given',
        'invented_price' => 'Invented a price', 'other' => 'Other',
    ];

    public function key(): string { return 'trip'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'trip'])->meta(['capability' => 'trip']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $board = TripBoard::forTenant($ctx->tenant);
        $e = EnquiryService::isTravel($ctx->tenant) ? $board->find((int) $id) : null;
        if (! $e) {
            return (new WorkspacePayload)
                ->object(['type' => 'trip', 'title' => 'Trip not found'])
                ->meta(['capability' => 'trip', 'error' => 'not_found']);
        }

        $state   = $e->state();
        $stage   = (string) $e->stage;
        $staff   = $board->staff();
        $who     = trim((string) $e->customer_name) ?: ($e->channel === 'web' ? 'Website visitor' : '+' . $e->customer_phone);
        $missing = EnquirySlots::missing($state);

        $stats = [
            ['key' => 'stage', 'label' => 'Stage', 'value' => TravelPipeline::label($stage), 'icon' => 'flag', 'tone' => TravelPipeline::tone($stage)],
            ['key' => 'party', 'label' => 'Travellers', 'value' => EnquirySlots::paxLine($state) ?: '—', 'icon' => 'user'],
            ['key' => 'owner', 'label' => 'Owner', 'value' => $e->owner_user_id ? ($staff[$e->owner_user_id] ?? '—') : 'Unassigned', 'icon' => 'user',
                'tone' => $e->owner_user_id ? 'default' : 'warn'],
            ['key' => 'last', 'label' => 'Customer last wrote', 'value' => $e->last_customer_at ? $e->last_customer_at->diffForHumans() : '—', 'icon' => 'clock'],
        ];

        $answers = [
            ['q' => 'Reference', 'value' => $e->reference(), 'sub' => 'Customer'],
            ['q' => 'Customer', 'value' => $who, 'sub' => 'Customer'],
            ['q' => 'Channel', 'value' => $e->channel === 'web' ? 'Website chat' : 'WhatsApp', 'sub' => 'Customer'],
        ];
        if (($contact = EnquiryBrief::contactLine($state)) !== '') {
            $answers[] = ['q' => 'Contact given', 'value' => $contact, 'sub' => 'Customer'];
        }
        $answers[] = ['q' => 'Confirmed by customer', 'sub' => 'Customer',
            'value' => $e->submitted_at ? $e->submitted_at->format('j M Y, H:i') : 'Not yet — the customer has not confirmed the summary'];
        foreach (EnquirySlots::summaryLines($state) as $i => $line) {
            $answers[] = ['q' => $i === 0 ? 'Trip' : '', 'value' => $line, 'sub' => 'Trip'];
        }
        if (! EnquirySlots::summaryLines($state)) {
            $answers[] = ['q' => 'Trip', 'value' => 'No details recorded yet', 'sub' => 'Trip'];
        }
        $answers[] = ['q' => 'Still needed', 'sub' => 'Trip',
            'value' => $missing ? ucfirst(implode('; ', array_map([TravelTurn::class, 'neededLabel'], $missing))) : 'Nothing — ready to price'];
        foreach ($state['pending'] as $q) {
            $answers[] = ['q' => 'Open question', 'sub' => 'Trip',
                'value' => 'Customer said "' . ($q['input'] ?? '') . '": ' . implode(' or ', (array) ($q['options'] ?? []))];
        }
        if ($e->is_group) {
            $answers[] = ['q' => 'Group booking', 'value' => '10 or more seats — handle personally', 'sub' => 'Trip'];
        }

        // Travellers read from the passports and ID cards the customer sent. Numbers are masked;
        // owners and managers can reveal one (logged).
        $travellers = $e->travellers()->get();
        $tToday = now()->format('Y-m-d');
        $tOn = Travellers::travelDate($state, $tToday);
        foreach ($travellers as $tv) {
            $facts = $tv->facts();
            $validity = Travellers::validity($facts, $tToday, $tOn, Travellers::returnDate($state));
            $answers[] = ['q' => 'Traveller', 'sub' => 'Travellers (from documents)', 'tone' => $validity ? 'warn' : 'default',
                'value' => Travellers::staffLine($facts, $tOn, $validity)];
        }
        if ($travellers->isNotEmpty()) {
            $answers[] = ['q' => '', 'sub' => 'Travellers (from documents)',
                'value' => 'Read from the machine-readable lines. Names have no check digit: compare them with the document before ticketing.'];
        }
        if ((string) $e->lost_reason !== '') {
            $answers[] = ['q' => 'Reason', 'value' => (string) $e->lost_reason, 'sub' => 'Outcome'];
        }

        // Staff handoff: who was alerted, and whether it arrived.
        $handoff = app(StaffHandoff::class);
        $hstatus = $handoff->status($ctx->tenant);
        $alerts  = TravelEnquiryNotification::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())
            ->where('enquiry_id', $e->id)->orderBy('id')->get();
        if (! $hstatus['configured']) {
            $answers[] = ['q' => 'Staff alert', 'sub' => 'Handoff', 'tone' => 'danger',
                'value' => 'No staff WhatsApp number is set, so nobody was alerted. Add one in Settings → Travel desk, then press "Send to staff".'];
        } elseif ($alerts->isEmpty()) {
            $answers[] = ['q' => 'Staff alert', 'sub' => 'Handoff',
                'value' => $e->submitted_at ? 'Not sent yet' : 'Sent when the customer confirms the summary'];
        }
        foreach ($alerts as $n) {
            $answers[] = ['q' => 'Staff alert to ' . self::maskNumber((string) $n->recipient), 'sub' => 'Handoff',
                'tone' => $n->status === 'sent' ? 'ok' : 'danger',
                'value' => $n->status === 'sent'
                    ? 'Delivered to WhatsApp ' . ($n->sent_at ? $n->sent_at->format('j M, H:i') : '')
                    : 'NOT delivered after ' . (int) $n->attempts . ' attempt' . ((int) $n->attempts === 1 ? '' : 's') . ((string) $n->last_error !== '' ? ' — ' . $n->last_error : '')];
        }
        foreach ($hstatus['rejected'] as $r) {
            $answers[] = ['q' => 'Number not used', 'sub' => 'Handoff', 'tone' => 'warn', 'value' => $r['number'] . ' — ' . $r['reason']];
        }

        $timeline = [];
        foreach ($board->events((int) $e->id)->reverse() as $ev) {
            $timeline[] = $this->timelineEntry($ev, $staff);
        }

        $base = '/api/v1/workspace/trip/' . $e->id;
        $actions = [];
        if ($e->conversation_id) {
            $actions[] = ['key' => 'open_chat', 'label' => 'Open conversation', 'method' => 'GET',
                'endpoint' => '/api/v1/workspace/chat/' . $e->conversation_id, 'actor' => 'human', 'tone' => 'primary'];
        }
        $next = array_values(array_diff(TravelPipeline::next($stage), [TravelPipeline::LOST]));
        if ($next) {
            $actions[] = ['key' => 'set_stage', 'label' => 'Change stage', 'method' => 'POST', 'endpoint' => $base . '/set_stage',
                'actor' => 'human', 'submitLabel' => 'Change stage',
                'schema' => ['stage' => 'enum:' . implode(',', $next), 'reason' => 'text'],
                'labels' => ['stage' => 'Move to', 'reason' => 'Reason (optional)']];
        }
        if (TravelPipeline::canMove($stage, TravelPipeline::LOST)) {
            $actions[] = ['key' => 'mark_lost', 'label' => 'Mark lost', 'method' => 'POST', 'endpoint' => $base . '/mark_lost',
                'actor' => 'human', 'tone' => 'danger', 'submitLabel' => 'Mark lost',
                'schema' => ['reason' => 'text'], 'labels' => ['reason' => 'Why was it lost?']];
        }
        if ($staff) {
            $combo = implode('|', array_map(fn ($uid, $name) => $uid . ' — ' . $name, array_keys($staff), $staff));
            $actions[] = ['key' => 'assign_owner', 'label' => 'Assign owner', 'method' => 'POST', 'endpoint' => $base . '/assign_owner',
                'actor' => 'human', 'submitLabel' => 'Assign',
                'schema' => ['owner' => 'combo:' . $combo], 'labels' => ['owner' => 'Staff member']];
        }
        $undelivered = $alerts->contains(fn ($n) => $n->status !== 'sent');
        if ($hstatus['configured'] && ($undelivered || $alerts->isEmpty()) && EnquirySlots::summaryLines($state)) {
            $actions[] = ['key' => 'retry_handoff', 'label' => $alerts->isEmpty() ? 'Send to staff' : 'Retry staff alert', 'method' => 'POST',
                'endpoint' => $base . '/retry_handoff', 'actor' => 'human', 'tone' => 'warn',
                'confirm' => 'Send this enquiry to the staff WhatsApp numbers set in Travel desk settings?'];
        }
        if ($travellers->isNotEmpty() && self::mayReveal($ctx)) {
            $actions[] = ['key' => 'reveal_document', 'label' => 'Show a document number', 'method' => 'POST', 'endpoint' => $base . '/reveal_document',
                'actor' => 'human', 'submitLabel' => 'Show',
                'confirm' => 'The full document number will be shown and your name recorded on the trip timeline.',
                'schema' => ['traveller' => 'combo:' . implode('|', $travellers->map(fn ($tv) => $tv->id . ' — ' . $tv->fullName())->all())],
                'labels' => ['traveller' => 'Traveller']];
        }
        $actions[] = ['key' => 'add_note', 'label' => 'Add note', 'method' => 'POST', 'endpoint' => $base . '/add_note',
            'actor' => 'human', 'submitLabel' => 'Save note',
            'schema' => ['note' => 'textarea'], 'labels' => ['note' => 'Note for the team']];
        $actions[] = ['key' => 'flag_ai', 'label' => 'Flag an incorrect AI answer', 'method' => 'POST', 'endpoint' => $base . '/flag_ai',
            'actor' => 'human', 'tone' => 'warn', 'submitLabel' => 'Flag',
            'schema' => ['category' => 'enum:' . implode(',', array_keys(self::FLAG_LABELS)), 'note' => 'text'],
            'labels' => ['category' => 'What went wrong', 'note' => 'What should it have said?']];

        return (new WorkspacePayload)
            ->object([
                'type' => 'trip', 'id' => (string) $e->id,
                'title' => EnquirySlots::route($state) ?: (implode(' → ', $state['brief']['destinations']) ?: (EnquiryBrief::servicesLine($state['brief']) ?: 'New travel enquiry')),
                'subtitle' => $who . ($e->depart_date ? ' · ' . EnquirySlots::niceDate($e->depart_date->format('Y-m-d')) : ''),
                'status' => $stage,
            ])
            ->headline(TravelPipeline::label($stage), 'stage', TravelPipeline::tone($stage))
            ->stats($stats)
            ->answers($answers)
            ->timeline($timeline)
            ->actions($actions)
            ->meta(['capability' => 'trip', 'ttl' => 15, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if (! EnquiryService::isTravel($ctx->tenant)) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        // The route id is authoritative (CommandController puts it in `id`); the tenant comes from
        // the signed-in user, never from the request.
        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $e) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $svc = app(EnquiryService::class);
        $uid = (int) $ctx->user->id;

        switch ($action) {
            case 'set_stage':
                $to = strtoupper(trim((string) ($input['stage'] ?? '')));
                if (! TravelPipeline::isStage($to)) {
                    return ['ok' => false, 'error' => 'bad_stage'];
                }
                if ($to === TravelPipeline::LOST) {
                    return ['ok' => false, 'error' => 'use_mark_lost'];
                }
                if (! $svc->changeStage($e, $to, 'staff', $uid, trim((string) ($input['reason'] ?? '')) ?: null)) {
                    return ['ok' => false, 'error' => 'move_not_allowed',
                        'message' => 'A trip cannot move from ' . TravelPipeline::label((string) $e->stage) . ' to ' . TravelPipeline::label($to) . '.'];
                }

                return ['ok' => true, 'message' => 'Stage changed to ' . TravelPipeline::label($to)];

            case 'mark_lost':
                $reason = trim((string) ($input['reason'] ?? ''));
                if ($reason === '') {
                    return ['ok' => false, 'error' => 'reason_required', 'message' => 'Please say why the trip was lost.'];
                }
                if (! $svc->changeStage($e, TravelPipeline::LOST, 'staff', $uid, $reason)) {
                    return ['ok' => false, 'error' => 'move_not_allowed'];
                }

                return ['ok' => true, 'message' => 'Trip marked lost'];

            case 'assign_owner':
                $ownerId = (int) preg_replace('/\D.*$/s', '', trim((string) ($input['owner'] ?? '')));   // "12 — Name" → 12
                $staff = TripBoard::forTenant($ctx->tenant)->staff();
                if (! isset($staff[$ownerId])) {
                    return ['ok' => false, 'error' => 'unknown_staff'];          // only this tenant's own staff
                }
                $svc->assignOwner($e, $ownerId, $uid);

                return ['ok' => true, 'message' => 'Assigned to ' . $staff[$ownerId]];

            case 'add_note':
                $note = trim((string) ($input['note'] ?? ''));
                if ($note === '') {
                    return ['ok' => false, 'error' => 'empty_note'];
                }
                $svc->addNote($e, $note, $uid);

                return ['ok' => true, 'message' => 'Note saved'];

            case 'reveal_document':
                if (! self::mayReveal($ctx)) {
                    return ['ok' => false, 'error' => 'forbidden', 'message' => 'Only an owner or a manager can see full document numbers.'];
                }
                $tid = (int) preg_replace('/\D.*$/s', '', trim((string) ($input['traveller'] ?? '')));   // "12 — Name" → 12
                $tv = $e->travellers()->where('id', $tid)->first();
                if (! $tv) {
                    return ['ok' => false, 'error' => 'unknown_traveller'];
                }
                try {
                    $number = (string) $tv->document_number;
                } catch (\Throwable $ex) {
                    return ['ok' => false, 'error' => 'unreadable', 'message' => 'The number cannot be decrypted (the application key has changed). Ask the customer for the document again.'];
                }
                // Who looked, and at whose document — never the number itself.
                $svc->log($e, 'document_revealed', 'staff', ['traveller_id' => (int) $tv->id], $uid);

                return ['ok' => true, 'message' => ucfirst(Travellers::documentLabel((string) $tv->document_type)) . ' number for ' . $tv->fullName() . ': ' . $number];

            case 'retry_handoff':
                $r = app(StaffHandoff::class)->retry($ctx->tenant, $e);
                if ($r['no_recipient']) {
                    return ['ok' => false, 'error' => 'no_recipient', 'message' => 'No staff WhatsApp number is set. Add one in Settings → Travel desk.'];
                }
                $svc->log($e, 'handoff_retried', 'staff', ['sent' => $r['sent'], 'failed' => $r['failed']], $uid);

                return $r['failed'] > 0 && $r['sent'] === 0
                    ? ['ok' => false, 'error' => 'send_failed', 'message' => 'The alert could not be delivered. The reason is shown on the trip.']
                    : ['ok' => true, 'message' => 'Sent to ' . $r['sent'] . ' staff number' . ($r['sent'] === 1 ? '' : 's') . ($r['failed'] > 0 ? '; ' . $r['failed'] . ' failed' : '')];

            case 'flag_ai':
                $svc->flagAi($e, (string) ($input['category'] ?? 'other'), (string) ($input['note'] ?? ''), $uid);

                return ['ok' => true, 'message' => 'Thank you — flagged for review'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /** @param array<int,string> $staff */
    /** "+2567••••1222": enough for staff to recognise a colleague's number without listing it in full. */
    private static function maskNumber(string $digits): string
    {
        return strlen($digits) <= 8 ? '+' . $digits : '+' . substr($digits, 0, 4) . '••••' . substr($digits, -4);
    }

    /** Full document numbers: owners (and admins) and managers only. */
    private static function mayReveal(CapabilityContext $ctx): bool
    {
        $user = $ctx->user;

        return (method_exists($user, 'isOwnerLike') && $user->isOwnerLike()) || (string) ($user->role ?? '') === 'manager';
    }

    private function timelineEntry(TravelEnquiryEvent $ev, array $staff): array
    {
        $meta  = is_array($ev->meta) ? $ev->meta : [];
        $actor = $ev->actor_user_id ? ($staff[$ev->actor_user_id] ?? 'Staff') : ucfirst((string) $ev->actor);
        [$title, $detail] = match ((string) $ev->type) {
            'created'           => ['Enquiry opened', ($meta['channel'] ?? '') === 'web' ? 'Website chat' : 'WhatsApp'],
            'details_changed'   => ['Trip details updated', implode(', ', array_map(fn ($f) => str_replace('_', ' ', (string) $f), (array) ($meta['fields'] ?? [])))],
            'stage_changed'     => ['Stage: ' . TravelPipeline::label((string) ($meta['to'] ?? '')),
                trim('from ' . TravelPipeline::label((string) ($meta['from'] ?? '')) . (isset($meta['reason']) ? ' — ' . $meta['reason'] : ''))],
            'owner_assigned'    => ['Owner assigned', $staff[(int) ($meta['owner_user_id'] ?? 0)] ?? '—'],
            'note'              => ['Note', (string) ($meta['note'] ?? '')],
            'handover_started'  => ['Staff took over the chat', (string) ($meta['reason'] ?? 'No reason given')],
            'handover_released' => ['Chat handed back to the bot', (string) ($meta['reason'] ?? '')],
            'ai_flagged'        => ['AI answer flagged: ' . (self::FLAG_LABELS[(string) ($meta['category'] ?? 'other')] ?? 'Other'), (string) ($meta['note'] ?? '')],
            'guard_hit'         => ['Guard corrected the AI', str_replace('_', ' ', (string) ($meta['guard'] ?? ''))],
            'validator_reject'  => ['A detail was not accepted', str_replace('_', ' ', (string) ($meta['code'] ?? '')) . ' (' . str_replace('_', ' ', (string) ($meta['field'] ?? '')) . ')'],
            'submitted'            => ['Customer confirmed the request', (string) ($meta['reference'] ?? '')],
            'handoff_sent'         => ['Sent to staff', (int) ($meta['sent'] ?? 0) . ' delivered' . ((int) ($meta['failed'] ?? 0) > 0 ? ', ' . (int) $meta['failed'] . ' failed' : '')],
            'handoff_failed'       => ['Staff alert NOT delivered', (int) ($meta['failed'] ?? 0) . ' failed'],
            'handoff_no_recipient' => ['No staff number set — nobody was alerted', ''],
            'passport_read'        => [($meta['duplicate'] ?? false) ? 'Same travel document sent again' : 'Travel document read: ' . (string) ($meta['document'] ?? 'document'),
                ($meta['party_filled'] ?? []) ? 'party updated from it: ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', (string) $f), (array) $meta['party_filled'])) : ''],
            'document_revealed'    => ['Full document number viewed', 'traveller #' . (int) ($meta['traveller_id'] ?? 0)],
            'handoff_retried'      => ['Staff alert retried', (int) ($meta['sent'] ?? 0) . ' delivered, ' . (int) ($meta['failed'] ?? 0) . ' failed'],
            default             => [ucfirst(str_replace('_', ' ', (string) $ev->type)), ''],
        };

        return (new TimelineEvent(
            at: $ev->created_at ? $ev->created_at->toIso8601String() : '',
            type: (string) $ev->type,
            title: $title,
            detail: $detail !== '' ? $detail : null,
            actor: $actor,
        ))->toArray();
    }
}
