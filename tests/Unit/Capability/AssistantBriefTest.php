<?php

namespace Tests\Unit\Capability;

use App\Capability\Shared\AssistantCapability;
use PHPUnit\Framework\TestCase;

class AssistantBriefTest extends TestCase
{
    public function test_portfolio_brief_fires_on_multiple_overdue(): void
    {
        $b = (new AssistantCapability())->customerBrief([
            'name' => 'Blue Logistics', 'overdue' => 18420, 'overdueCount' => 4,
            'maxDaysLate' => 38, 'exposure' => 18420, 'balance' => 18420,
            'endpoint' => '/api/v1/workspace/customer/7',
        ])->toArray();

        $this->assertSame('high', $b['level']);
        $this->assertStringContainsString('4 invoices overdue', $b['situation']);
        $this->assertSame('/api/v1/workspace/customer/7/record_payment', $b['actions'][0]['endpoint']);
        $this->assertSame('human', $b['actions'][0]['actor']);
    }

    public function test_healthy_account_is_low_risk_with_no_actions(): void
    {
        $b = (new AssistantCapability())->customerBrief([
            'name' => 'Nile', 'overdue' => 0, 'overdueCount' => 0, 'exposure' => 0, 'balance' => 0, 'endpoint' => '/x',
        ])->toArray();

        $this->assertSame('low', $b['level']);
        $this->assertSame('ok', $b['risk']['tone']);
        $this->assertSame([], $b['actions']);
    }
}
