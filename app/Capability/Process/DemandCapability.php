<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Growth\UnmetDemandService;

/**
 * v4.58 — Asked for, not stocked.
 *
 * The demand the shop has already turned away, added up. Both halves were being recorded and
 * neither was being counted: storefront searches that found nothing, and the bot noticing a
 * customer asked for something not in the catalogue.
 *
 * The column that matters is **Customers**, not Times. One person asking ten times is usually one
 * person having a bad day; ten people asking once each is a product you should be selling. And the
 * phone numbers ride along, because the people who asked are the people who will buy it the week
 * it arrives — which is the difference between a report and a decision.
 */
final class DemandCapability implements ProcessCapability
{
    public function key(): string { return 'demand'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $data = app(UnmetDemandService::class)->forTenant($ctx->tenant);
        $rows = [];
        $withPeople = 0;

        foreach ($data['rows'] as $r) {
            if ($r['customers'] > 0) {
                $withPeople++;
            }
            $srcs = $r['sources'];
            $rows[] = [
                'item'      => ['value' => (string) $r['label'], 'avatar' => true],
                'customers' => ['value' => (int) $r['customers'],
                                'tone' => $r['customers'] >= 3 ? 'danger' : ($r['customers'] > 0 ? 'warn' : '')],
                'times'     => (int) $r['times'],
                // Searches carry no phone — search_events has nowhere to keep one — so a term that
                // only ever appeared as a search shows demand with nobody to ring, and says so.
                'callback'  => $r['customers'] > 0
                    ? ['value' => implode(', ', array_slice($r['phones'], 0, 3))
                        . ($r['customers'] > 3 ? ' +' . ($r['customers'] - 3) . ' more' : '')]
                    : ['value' => 'searched, no number'],
                'where'     => ['value' => in_array('asked', $srcs, true)
                    ? (in_array('search', $srcs, true) ? 'Asked & searched' : 'Asked the bot')
                    : 'Searched the shop'],
                'last'      => (string) ($r['last'] ?? ''),
            ];
        }

        $columns = [
            ['key' => 'item',      'label' => 'Asked for',  'type' => 'avatar', 'sortable' => true],
            ['key' => 'customers', 'label' => 'Customers',  'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'times',     'label' => 'Times',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'callback',  'label' => 'Ring when it lands', 'sortable' => false],
            ['key' => 'where',     'label' => 'Where',      'sortable' => true],
            ['key' => 'last',      'label' => 'Last asked', 'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:demand', 'title' => 'Asked for, not stocked',
                'subtitle' => count($rows) . ' things customers wanted in the last ' . $data['window'] . ' days',
            ])
            ->financials([
                ['key' => 'items', 'label' => 'Things asked for', 'value' => count($rows),
                 'foot' => 'last ' . $data['window'] . ' days'],
                ['key' => 'people', 'label' => 'With someone to ring', 'value' => $withPeople,
                 'tone' => $withPeople > 0 ? 'warn' : 'ok', 'foot' => 'a customer asked by name'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'demand', 'ttl' => 600, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['demand' => count(app(UnmetDemandService::class)->forTenant($ctx->tenant)['rows'])];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action === 'refresh') {
            UnmetDemandService::forget((int) $ctx->tenantId());

            return ['ok' => true, 'message' => 'Recounted from the last 90 days.'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
