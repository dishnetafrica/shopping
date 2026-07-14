<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\ActionSpec;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Shared\MoneyTimingCapability;
use App\Capability\Support\Money;

/**
 * Level-7 CEO brief. Composes PROCESS capabilities only (never queries objects). A plan, not a dashboard.
 * Every number traces to a frozen service via the process summaries.
 */
final class MorningBriefingCapability implements ProcessCapability
{
    public function __construct(
        private CollectionsCapability $collections,
        private SalesProcessCapability $sales,
        private PurchaseProcessCapability $purchase,
        private WarehouseCapability $warehouse,
        private DispatchCapability $dispatch,
        private MoneyTimingCapability $money,
        private ?AdsCapability $ads = null,
    ) {}

    public function key(): string { return 'briefing'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $col = $this->collections->summary($ctx);
        $sal = $this->sales->summary($ctx);
        $pur = $this->purchase->summary($ctx);
        $war = $this->warehouse->summary($ctx);
        $dis = $this->dispatch->summary($ctx);
        $cash = $this->money->position($ctx);
        $ad = $this->ads ? $this->ads->summary($ctx) : ['enabled' => true, 'arrivals7d' => 0];
        $cur = $ctx->baseCurrency();

        $adArrivals = (int) ($ad['arrivals7d'] ?? 0);

        $metrics = [
            ['label' => 'Cash on hand', 'value' => Money::fmt($cash['cash'], 'USD'), 'tone' => 'ok'],
            ['label' => 'Runway', 'value' => ($cash['runway'] ?? '∞') . ' days', 'tone' => ($cash['runway'] !== null && $cash['runway'] < 14) ? 'warn' : 'ok'],
            ['label' => 'To collect', 'value' => Money::fmt($col['exposure'], $cur), 'tone' => 'danger'],
            ['label' => 'To invoice', 'value' => Money::fmt($sal['toInvoiceValue'], $cur), 'tone' => 'warn'],
            ['label' => 'Payable', 'value' => Money::fmt($pur['payable'], $cur), 'tone' => 'warn'],
            ['label' => 'Reorder', 'value' => (string) $war['reorder'], 'tone' => 'warn'],
            ['label' => 'Deliveries', 'value' => (string) $dis['inTransit'], 'tone' => 'default'],
            ['label' => 'Ad arrivals (7d)', 'value' => (string) $adArrivals, 'tone' => $adArrivals > 0 ? 'ok' : 'default'],
        ];

        $plan = [];
        if ($col['overdueCount'] > 0) {
            $plan[] = new ActionSpec('collect', 'Chase ' . $col['overdueCount'] . ' overdue invoices — ' . Money::fmt($col['exposure'], $cur), '/api/v1/workspace/collections', 'GET', 'human', 'primary');
        }
        if ($sal['toInvoice'] > 0) {
            $plan[] = new ActionSpec('invoice', 'Invoice ' . $sal['toInvoice'] . ' delivered orders — ' . Money::fmt($sal['toInvoiceValue'], $cur), '/api/v1/workspace/sales.process', 'GET', 'human');
        }
        if ($war['reorder'] > 0) {
            $plan[] = new ActionSpec('reorder', 'Reorder ' . $war['reorder'] . ' low-stock SKUs', '/api/v1/workspace/warehouse', 'GET', 'human');
        }
        if ($pur['payable'] > 0) {
            $plan[] = new ActionSpec('pay', 'Review ' . Money::fmt($pur['payable'], $cur) . ' in supplier bills', '/api/v1/workspace/purchase.process', 'GET', 'human');
        }
        if ($adArrivals > 0) {
            $adEnabled = (bool) ($ad['enabled'] ?? true);
            $plan[] = $adEnabled
                ? new ActionSpec('ads', $adArrivals . ' customer' . ($adArrivals === 1 ? '' : 's') . ' arrived from ads this week — follow up', '/api/v1/workspace/ads', 'GET', 'human')
                : new ActionSpec('ads', $adArrivals . ' arrived from ads but ad understanding is OFF — turn it on', '/api/v1/workspace/ads', 'GET', 'human', 'primary');
        }

        return (new WorkspacePayload)
            ->object(['type' => 'process:briefing', 'title' => 'Morning briefing'])
            ->headline(Money::fmt($cash['cash'], 'USD'), 'cash · ' . ($cash['runway'] ?? '∞') . ' days runway', ($cash['runway'] !== null && $cash['runway'] < 14) ? 'warn' : 'ok')
            ->brief(null)
            ->financials(array_map(fn ($m) => ['key' => strtolower(str_replace(' ', '_', $m['label'])), 'label' => $m['label'], 'value' => $m['value'], 'tone' => $m['tone']], $metrics))
            ->actions($plan)
            ->meta(['capability' => 'briefing', 'ttl' => 3600, 'note' => 'composed from process summaries']);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return $this->project($ctx)->toArray()['financials'];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
