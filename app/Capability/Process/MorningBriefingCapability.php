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
        // Quotations awaiting staff confirmation/convert (e.g. the quote-first bot flow). Direct count
        // so the briefing surfaces the to-do without a new capability dependency.
        // V4.2 AI desk — direct counts (same precedent as quotesToConfirm below).
        // Fail-soft: before `php artisan migrate` these tables/columns may not exist —
        // the briefing must degrade to zeros, never 500 (2026-07-18 incident hardening).
        try {
            $aiPending = (int) \App\Models\FollowupProposal::where('tenant_id', $ctx->tenantId())
                ->where('status', 'draft')->count();
        } catch (\Throwable $e) {
            $aiPending = 0;
        }
        try {
            $aiEscalations = (int) \App\Models\CustomerProfile::withoutGlobalScopes()
                ->where('tenant_id', $ctx->tenantId())->where('follow_up', 'like', 'ESCALATED:%')->count();
        } catch (\Throwable $e) {
            $aiEscalations = 0;
        }

        $quotesToConfirm = (int) \App\Models\Quotation::where('tenant_id', $ctx->tenantId())
            ->whereRaw("lower(coalesce(status,'')) in ('sent','accepted','pending')")
            ->whereNull('order_id')->count();

        $metrics = [
            ['label' => 'Cash on hand', 'value' => Money::fmt($cash['cash'], 'USD'), 'tone' => 'ok'],
            ['label' => 'Runway', 'value' => ($cash['runway'] ?? '∞') . ' days', 'tone' => ($cash['runway'] !== null && $cash['runway'] < 14) ? 'warn' : 'ok'],
            ['label' => 'To collect', 'value' => Money::fmt($col['exposure'], $cur), 'tone' => 'danger'],
            ['label' => 'To invoice', 'value' => Money::fmt($sal['toInvoiceValue'], $cur), 'tone' => 'warn'],
            ['label' => 'Payable', 'value' => Money::fmt($pur['payable'], $cur), 'tone' => 'warn'],
            ['label' => 'Reorder', 'value' => (string) $war['reorder'], 'tone' => 'warn'],
            ['label' => 'Deliveries', 'value' => (string) $dis['inTransit'], 'tone' => 'default'],
            ['label' => 'Ad arrivals (7d)', 'value' => (string) $adArrivals, 'tone' => $adArrivals > 0 ? 'ok' : 'default'],
            ['label' => 'Quotes to confirm', 'value' => (string) $quotesToConfirm, 'tone' => $quotesToConfirm > 0 ? 'warn' : 'default'],
        ];

        $plan = [];
        if ($col['overdueCount'] > 0) {
            $plan[] = new ActionSpec('collect', 'Chase ' . $col['overdueCount'] . ' overdue invoices — ' . Money::fmt($col['exposure'], $cur), '/api/v1/workspace/collections', 'GET', 'human', 'primary');
        }
        if ($sal['toInvoice'] > 0) {
            $plan[] = new ActionSpec('invoice', 'Invoice ' . $sal['toInvoice'] . ' delivered orders — ' . Money::fmt($sal['toInvoiceValue'], $cur), '/api/v1/workspace/sales.process', 'GET', 'human');
        }
        if ($quotesToConfirm > 0) {
            $plan[] = new ActionSpec('quotes', $quotesToConfirm . ' quotation' . ($quotesToConfirm === 1 ? '' : 's') . ' awaiting your confirmation — review & convert to orders', '/api/v1/workspace/quotations', 'GET', 'human', 'primary');
        }
        if ($war['reorder'] > 0) {
            $plan[] = new ActionSpec('reorder', 'Reorder ' . $war['reorder'] . ' low-stock SKUs', '/api/v1/workspace/warehouse', 'GET', 'human');
        }
        // WhatsApp connection line (2026-07-18): a dead line is the FIRST thing to know each morning.
        $waT = \App\Models\Tenant::find($ctx->tenantId());
        if ($waT && (string) $waT->setting('wa_status', 'open') === 'down') {
            $since = (string) $waT->setting('wa_down_since', '');
            $plan[] = new ActionSpec('wa_down',
                "\u{1F534} WhatsApp is DISCONNECTED".($since !== '' ? ' since '.\Illuminate\Support\Carbon::parse($since)->diffForHumans() : '')
                .' — customers cannot reach the bot. Check the connection from Chats.',
                '/api/v1/workspace/chats', 'GET', 'human', 'primary');
        }

        if ($aiPending > 0) {
            $plan[] = new ActionSpec('ai_plan', "Review the AI's plan for today — " . $aiPending . ' follow-up' . ($aiPending === 1 ? '' : 's') . ' awaiting your judgment', '/api/v1/workspace/followups', 'GET', 'human', 'primary');
        }
        if ($aiEscalations > 0) {
            $plan[] = new ActionSpec('ai_esc', '🚨 ' . $aiEscalations . ' escalated customer' . ($aiEscalations === 1 ? '' : 's') . ' need a human — open Chats', '/api/v1/workspace/chats', 'GET', 'human', 'primary');
        }
        if (false) {
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
