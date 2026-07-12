<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Reports\ReportRegistry;
use CloudBSS\Infra\Reporting\TrialBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E7.4 — P&L and Trial Balance report VIEWS over the EXISTING (frozen) ledger. The reports
 * read TrialBalance (SSOT) and expose it through the report contract with no accounting change. When
 * the ledger is disabled they return nothing; when enabled they convert the ledger's minor units to
 * major-unit amounts and honour the trial-balance type filter.
 */
class LedgerReportTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $ledger): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('ledger_enabled', $ledger);
        $t->putSetting('currency', 'USD');
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        return $t;
    }

    /** minor-unit factor the reports use (mirrors BooksController::scope); robust if Currency throws. */
    private function factor(): int
    {
        $f = 1;
        try { $f = (int) \CloudBSS\Kernel\Money\Currency::of('USD')->subunit(); } catch (\Throwable $e) { $f = 1; }
        return $f < 1 ? 1 : $f;
    }

    private function bindTrialBalance(): void
    {
        $fake = new class {
            public function profitAndLoss($tid, $eid, $cur): array
            {
                return ['income' => 100000, 'expense' => 40000, 'net_profit' => 60000];
            }
            public function trialBalance($tid, $eid, $cur): array
            {
                return [
                    'rows' => [
                        ['account' => '1000', 'name' => 'Cash', 'type' => 'asset', 'debit' => 100000, 'credit' => 0],
                        ['account' => '4000', 'name' => 'Sales', 'type' => 'income', 'debit' => 0, 'credit' => 100000],
                    ],
                    'total_debit' => 100000, 'total_credit' => 100000, 'balanced' => true,
                ];
            }
        };
        $this->app->instance(TrialBalance::class, $fake);
    }

    public function test_reports_are_registered(): void
    {
        $reg = ReportRegistry::default();
        $this->assertTrue($reg->has('pnl'));
        $this->assertTrue($reg->has('trial_balance'));
    }

    public function test_disabled_ledger_returns_nothing(): void
    {
        $t = $this->tenant(false);
        $this->assertSame([], ReportRegistry::default()->get('pnl')->rows($t));
        $this->assertSame([], ReportRegistry::default()->get('trial_balance')->rows($t));
    }

    public function test_pnl_converts_minor_units(): void
    {
        $t = $this->tenant(true);
        $this->bindTrialBalance();
        $f = $this->factor();

        $rows = ReportRegistry::default()->get('pnl')->rows($t);
        $this->assertSame('Income', $rows[0]['line']);
        $this->assertSame(round(100000 / $f, 2), $rows[0]['amount']);
        $this->assertSame(round(60000 / $f, 2), $rows[2]['amount']); // net profit
    }

    public function test_trial_balance_maps_and_filters(): void
    {
        $t = $this->tenant(true);
        $this->bindTrialBalance();
        $f = $this->factor();

        $all = ReportRegistry::default()->get('trial_balance')->rows($t);
        $this->assertCount(2, $all);
        $this->assertSame(round(100000 / $f, 2), $all[0]['debit']);

        $income = ReportRegistry::default()->get('trial_balance')->rows($t, ['type' => 'income']);
        $this->assertCount(1, $income);
        $this->assertSame('Sales', $income[0]['name']);
    }
}
