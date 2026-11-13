<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\TravelEnquiry;
use App\Services\Travel\Enquiry\EnquiryService;
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
                'trip'    => ['value' => self::shortRoute($state) ?: 'Details being collected', 'sub' => $who, 'avatar' => true],
                'dates'   => ['value' => $dates !== '' ? $dates : '—', 'sort' => (string) ($e->depart_date?->format('Y-m-d') ?? '')],
                'party'   => ['value' => EnquirySlots::paxLine($state) ?: '—'],
                'stage'   => ['value' => TravelPipeline::label((string) $e->stage) . ($abandoned ? ' · no reply' : ''),
                    'badge' => $abandoned ? 'danger' : TravelPipeline::tone((string) $e->stage), 'sort' => (string) $e->stage],
                'owner'   => ['value' => $e->owner_user_id ? ($staff[$e->owner_user_id] ?? '—') : 'Unassigned', 'sort' => (string) ($staff[$e->owner_user_id] ?? '')],
                'updated' => ['value' => $e->updated_at ? $e->updated_at->diffForHumans() : '—', 'sort' => (string) ($e->updated_at?->getTimestamp() ?? 0)],
                'link'    => '/api/v1/workspace/trip/' . $e->id,
            ];
        }

        $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:trips', 'title' => 'Trips',
                'subtitle' => $m['total'] . ' enquiries · ' . $m['open'] . ' open · ' . $m['ready_to_price'] . ' ready to price',
            ])
            ->financials([
                ['key' => 'ready', 'label' => 'Ready to price', 'value' => (string) $m['ready_to_price'], 'tone' => $m['ready_to_price'] > 0 ? 'warn' : 'ok', 'foot' => 'all details collected'],
                ['key' => 'open', 'label' => 'Open enquiries', 'value' => (string) $m['open']],
                ['key' => 'abandoned', 'label' => 'No reply', 'value' => (string) $m['abandoned'], 'tone' => $m['abandoned'] > 0 ? 'danger' : 'ok', 'foot' => 'silent for ' . $board->abandonHours() . 'h+'],
                ['key' => 'ai', 'label' => 'Handled by AI alone', 'value' => $pct($m['successful_ai_pct']), 'foot' => $m['successful_ai'] . ' of ' . $m['total'] . ' reached full details'],
                ['key' => 'handover', 'label' => 'Needed a person', 'value' => $pct($m['human_handover_pct']), 'foot' => $m['human_handover'] . ' enquiries'],
                ['key' => 'incorrect', 'label' => 'AI corrections', 'value' => (string) $m['incorrect_responses'], 'tone' => $m['flagged_answers'] > 0 ? 'warn' : 'default',
                    'foot' => $m['flagged_answers'] . ' flagged by staff · ' . $m['guard_hits'] . ' caught by guards'],
            ])
            ->table(
                [
                    ['key' => 'trip', 'label' => 'Trip', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'dates', 'label' => 'Dates', 'sortable' => true],
                    ['key' => 'party', 'label' => 'Travellers'],
                    ['key' => 'stage', 'label' => 'Stage', 'type' => 'badge', 'sortable' => true],
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

        return implode(' · ', $legs);
    }
}
