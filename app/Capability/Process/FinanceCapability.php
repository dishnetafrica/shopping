<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Financial;
use App\Capability\Shared\MoneyTimingCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;

/** Finance workspace — cash, receivables, payables, runway. Merges the legacy cash screens. */
final class FinanceCapability implements ProcessCapability
{
    public function __construct(private MoneyTimingCapability $money) {}

    public function key(): string { return 'finance'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $m = $this->money->position($ctx);

        return (new WorkspacePayload)
            ->object(['type' => 'process:finance', 'title' => 'Finance'])
            ->headline(Money::fmt($m['cash'], 'USD'), 'cash on hand', 'ok')
            ->financials([
                new Financial('cash', 'Cash on hand', $m['cash'], 'USD', 'ok'),
                new Financial('receivable', 'Receivable', $m['receivable'], 'USD', 'default'),
                new Financial('payable', 'Payable', $m['payable'], 'USD', 'warn'),
                new Financial('runway', 'Runway', $m['runway'] ?? '∞', null, ($m['runway'] !== null && $m['runway'] < 14) ? 'warn' : 'ok', 'days'),
            ])
            ->actions([
                ['key' => 'export_pdf', 'label' => 'Cashbook PDF', 'href' => '/api/v1/doc/cashbook-export?format=pdf', 'actor' => 'human', 'tone' => 'default'],
                ['key' => 'export_csv', 'label' => 'Cashbook CSV (Excel)', 'href' => '/api/v1/doc/cashbook-export?format=csv', 'actor' => 'human', 'tone' => 'default'],
            ])
            ->meta(['capability' => 'finance', 'ttl' => 120, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return $this->money->position($ctx);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
