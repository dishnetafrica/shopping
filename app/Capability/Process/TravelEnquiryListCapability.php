<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\TravelEnquiry;
use App\Services\Travel\Enquiry\EnquiryBrief;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Handoff\StaffHandoff;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Enquiry\TravelPipeline;
use App\Services\Travel\Panel\TripBoard;
use App\Services\Travel\Quality\EnquiryMetrics;

/**
 * Trips — the V3 list of travel enquiries (Travel Vertical · M1). V3-native. One row per enquiry
 * with its route, dates, party, pipeline stage and owner; rows deep-link to the trip detail.
 * Travel agencies only: for any other business type the screen is empty and says why.
 */
final class TravelEnquiryListCapability implements ProcessCapability
{
    public function key(): string { return 'trips'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        if (! EnquiryService::isTravel($ctx->tenant)) {
            return (new WorkspacePayload)
                ->object(['type' => 'process:trips', 'title' => 'Trips', 'subtitle' => 'Available for travel agencies'])
                ->table([['key' => 'trip', 'label' => 'Trip']], [], [], ['pageSize' => 20])
                ->meta(['capability' => 'trips', 'ttl' => 300, 'permissions' => []]);
        }

        $board     = TripBoard::forTenant($ctx->tenant);
        $enquiries = $board->enquiries();
        $staff     = $board->staff();
        $rowsM     = $board->metricRows($enquiries);
        $m         = EnquiryMetrics::compute(array_values($rowsM), time(), $board->abandonHours());

        // Staff alerts: are they set up, and did any fail?
        $handoff   = app(StaffHandoff::class)->status($ctx->tenant);
        $undelivered = $enquiries->filter(fn ($e) => in_array((string) $e->handoff_status, ['failed', 'partial', 'no_recipient'], true) && $e->submitted_at !== null)->count();
        $handoffLabels = ['sent' => ['Staff alerted', 'ok'], 'partial' => ['Partly delivered', 'warn'], 'failed' => ['Alert failed', 'danger'], 'no_recipient' => ['Nobody alerted', 'danger']];

        $rows = [];
        foreach ($enquiries as $e) {
            $state = $e->state();
            $who   = trim((string) $e->customer_name) ?: ($e->channel === 'web' ? 'Website visitor' : '+' . $e->customer_phone);
            $dates = EnquirySlots::niceDate($e->depart_date?->format('Y-m-d'));
            if ($e->return_date) {
                $dates .= ' – ' . EnquirySlots::niceDate($e->return_date->format('Y-m-d'));
            }
            $abandoned = EnquiryMetrics::isAbandoned((string) $e->stage, $rowsM[$e->id]['last_customer_at'] ?? null, time(), $board->abandonHours());
            $rows[] = [
                'trip'    => ['value' => self::shortRoute($state) ?: 'Details being collected',
                    'sub' => trim($who . ' · ' . $e->reference() . (EnquiryBrief::servicesLine($state['brief']) !== '' ? ' · ' . EnquiryBrief::servicesLine($state['brief']) : '')), 'avatar' => true],
                'dates'   => ['value' => $dates !== '' ? $dates : '—', 'sort' => (string) ($e->depart_date?->format('Y-m-d') ?? '')],
                'party'   => ['value' => EnquirySlots::paxLine($state) ?: '—'],
                'stage'   => ['value' => TravelPipeline::label((string) $e->stage) . ($abandoned ? ' · no reply' : ''),
                    'badge' => $abandoned ? 'danger' : TravelPipeline::tone((string) $e->stage), 'sort' => (string) $e->stage],
                'handoff' => $e->submitted_at === null
                    ? ['value' => EnquirySlots::isComplete($state) ? 'Awaiting customer\'s confirmation' : 'Collecting details', 'badge' => 'default', 'sort' => '0']
                    : ['value' => $handoffLabels[(string) $e->handoff_status][0] ?? 'Confirmed', 'badge' => $handoffLabels[(string) $e->handoff_status][1] ?? 'default', 'sort' => (string) $e->handoff_status],
                'owner'   => ['value' => $e->owner_user_id ? ($staff[$e->owner_user_id] ?? '—') : 'Unassigned', 'sort' => (string) ($staff[$e->owner_user_id] ?? '')],
                'updated' => ['value' => $e->updated_at ? $e->updated_at->diffForHumans() : '—', 'sort' => (string) ($e->updated_at?->getTimestamp() ?? 0)],
                'link'    => '/api/v1/workspace/trip/' . $e->id,
            ];
        }

        $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:trips', 'title' => 'Trips',
                'subtitle' => $m['total'] . ' enquiries · ' . $m['open'] . ' open · ' . $m['ready_to_price'] . ' ready to price'
                    . ($handoff['configured'] ? '' : ' · ⚠ no staff WhatsApp number is set, so nobody is alerted'),
            ])
            ->financials(array_merge($handoff['configured'] ? [] : [
                ['key' => 'handoff_setup', 'label' => 'Staff alerts', 'value' => 'Not set up', 'tone' => 'danger',
                    'foot' => 'Enquiries are saved here but nobody is alerted. Add staff WhatsApp numbers in Settings → Travel desk.'],
            ], $undelivered > 0 ? [
                ['key' => 'handoff_failed', 'label' => 'Alerts not delivered', 'value' => (string) $undelivered, 'tone' => 'danger', 'foot' => 'open each one and press Retry staff alert'],
            ] : [], [
                ['key' => 'ready', 'label' => 'Ready to price', 'value' => (string) $m['ready_to_price'], 'tone' => $m['ready_to_price'] > 0 ? 'warn' : 'ok', 'foot' => 'all details collected'],
                ['key' => 'open', 'label' => 'Open enquiries', 'value' => (string) $m['open']],
                ['key' => 'abandoned', 'label' => 'No reply', 'value' => (string) $m['abandoned'], 'tone' => $m['abandoned'] > 0 ? 'danger' : 'ok', 'foot' => 'silent for ' . $board->abandonHours() . 'h+'],
                ['key' => 'ai', 'label' => 'Handled by AI alone', 'value' => $pct($m['successful_ai_pct']), 'foot' => $m['successful_ai'] . ' of ' . $m['total'] . ' reached full details'],
                ['key' => 'handover', 'label' => 'Needed a person', 'value' => $pct($m['human_handover_pct']), 'foot' => $m['human_handover'] . ' enquiries'],
                ['key' => 'incorrect', 'label' => 'AI corrections', 'value' => (string) $m['incorrect_responses'], 'tone' => $m['flagged_answers'] > 0 ? 'warn' : 'default',
                    'foot' => $m['flagged_answers'] . ' flagged by staff · ' . $m['guard_hits'] . ' caught by guards'],
            ]))
            ->table(
                [
                    ['key' => 'trip', 'label' => 'Trip', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'dates', 'label' => 'Dates', 'sortable' => true],
                    ['key' => 'party', 'label' => 'Travellers'],
                    ['key' => 'stage', 'label' => 'Stage', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'handoff', 'label' => 'Handoff', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'owner', 'label' => 'Owner', 'sortable' => true],
                    ['key' => 'updated', 'label' => 'Updated', 'sortable' => true],
                ],
                $rows,
                [['key' => 'stage', 'label' => 'Stage'], ['key' => 'owner', 'label' => 'Owner']],
                ['pageSize' => 20])
            ->actions([])
            ->meta(['capability' => 'trips', 'ttl' => 20, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        if (! EnquiryService::isTravel($ctx->tenant)) {
            return ['open' => 0, 'ready' => 0];
        }
        $q = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId());

        return [
            'open'  => (clone $q)->whereNull('closed_at')->count(),
            'ready' => (clone $q)->where('stage', TravelPipeline::DETAILS_COLLECTED)->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /** "Entebbe → Dubai" (cities only, for a narrow column). */
    private static function shortRoute(array $state): string
    {
        $legs = [];
        foreach (($state['segments'] ?? []) as $seg) {
            $from = (string) ($seg['from']['city'] ?? '');
            $to   = (string) ($seg['to']['city'] ?? '');
            if ($from === '' && $to === '') {
                continue;
            }
            $legs[] = ($from !== '' ? $from : '?') . ' → ' . ($to !== '' ? $to : '?');
        }

        if (! $legs) {
            $places = $state['brief']['destinations'] ?? [];

            return $places ? implode(' → ', $places) : '';
        }

        return implode(' · ', $legs);
    }
}
