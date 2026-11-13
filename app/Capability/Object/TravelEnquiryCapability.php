<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\TimelineEvent;
use App\Capability\Dto\WorkspacePayload;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Enquiry\TravelPipeline;
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
            ['q' => 'Customer', 'value' => $who, 'sub' => 'Customer'],
            ['q' => 'Channel', 'value' => $e->channel === 'web' ? 'Website chat' : 'WhatsApp', 'sub' => 'Customer'],
        ];
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
        if ((string) $e->lost_reason !== '') {
            $answers[] = ['q' => 'Reason', 'value' => (string) $e->lost_reason, 'sub' => 'Outcome'];
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
                'title' => EnquirySlots::route($state) ?: 'New travel enquiry',
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

            case 'flag_ai':
                $svc->flagAi($e, (string) ($input['category'] ?? 'other'), (string) ($input['note'] ?? ''), $uid);

                return ['ok' => true, 'message' => 'Thank you — flagged for review'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /** @param array<int,string> $staff */
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
