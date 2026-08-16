<?php

namespace Tests\Unit;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Money\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E5.1 — CashService is the ONE signed-sum cash-balance rule. This proves it produces the
 * SAME per-currency balance as the Trade Cashbook's headline computation (no divergence — the whole
 * point of consolidating), that the credit rule is honoured (payables move no cash), and that the
 * holder/currency/status filters behave.
 */
class CashServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Literal replication of TradeCashbookController::data()'s headline $bal loop — the oracle. */
    private function tradeCashbookBalances(Tenant $t, ?string $holder = null): array
    {
        $q = OrderCost::where('tenant_id', $t->id)->where('status', 'approved')
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'));
        if ($holder !== null) $q->where('holder', $holder);

        $bal = ['USD' => 0, 'UGX' => 0, 'SSP' => 0];
        foreach ($q->get(['currency', 'stage', 'paid_native']) as $r) {
            $sign = in_array($r->stage, ['funding', 'cash_in', 'customer_payment'], true) ? 1 : -1;
            if (isset($bal[$r->currency])) $bal[$r->currency] += $sign * (float) $r->paid_native;
        }
        return array_map(fn ($v) => round($v, 2), $bal);
    }

    private function cost(Tenant $t, array $o): void
    {
        OrderCost::create(array_merge([
            'tenant_id' => $t->id, 'currency' => 'UGX', 'native_amount' => 0, 'rate_to_usd' => 3655,
            'usd_amount' => 0, 'status' => 'approved', 'holder' => 'Ann', 'pay_status' => 'cash',
        ], $o));
    }

    private function seedCosts(Tenant $t): void
    {
        // cash-in stages (+), across currencies + holders
        $this->cost($t, ['stage' => 'funding',          'currency' => 'USD', 'native_amount' => 1000, 'paid_native' => 1000, 'holder' => 'Ann']);
        $this->cost($t, ['stage' => 'cash_in',          'currency' => 'UGX', 'native_amount' => 500000, 'paid_native' => 500000, 'holder' => 'Ann']);
        $this->cost($t, ['stage' => 'customer_payment', 'currency' => 'SSP', 'native_amount' => 20000, 'paid_native' => 20000, 'holder' => 'Bob']);
        // cash-out stages (−)
        $this->cost($t, ['stage' => 'cash_out',         'currency' => 'UGX', 'native_amount' => 120000, 'paid_native' => 120000, 'holder' => 'Ann']);
        $this->cost($t, ['stage' => 'purchase',         'currency' => 'USD', 'native_amount' => 300, 'paid_native' => 300, 'holder' => 'Bob']);
        $this->cost($t, ['stage' => 'supplier_payment', 'currency' => 'SSP', 'native_amount' => 5000, 'paid_native' => 5000, 'holder' => 'Bob']);
        // credit purchase: moves NO cash → excluded (paid_native 0 + pay_status credit)
        $this->cost($t, ['stage' => 'purchase',         'currency' => 'USD', 'native_amount' => 999, 'paid_native' => 0, 'pay_status' => 'credit', 'holder' => 'Ann']);
        // pending + void rows: excluded by the approved-only rule
        $this->cost($t, ['stage' => 'cash_in',          'currency' => 'UGX', 'native_amount' => 777, 'paid_native' => 777, 'status' => 'pending', 'holder' => 'Ann']);
        $this->cost($t, ['stage' => 'cash_in',          'currency' => 'UGX', 'native_amount' => 888, 'paid_native' => 888, 'status' => 'void', 'holder' => 'Ann']);
    }

    public function test_matches_trade_cashbook_total_no_divergence(): void
    {
        $t = Tenant::factory()->create();
        $this->seedCosts($t);

        $this->assertSame(
            $this->tradeCashbookBalances($t),
            CashService::forTenant($t)->balances(),
            'CashService must equal the Trade Cashbook headline balance exactly'
        );
    }

    public function test_matches_per_holder(): void
    {
        $t = Tenant::factory()->create();
        $this->seedCosts($t);

        foreach (['Ann', 'Bob'] as $h) {
            $this->assertSame(
                $this->tradeCashbookBalances($t, $h),
                CashService::forTenant($t)->balances(['holder' => $h]),
                "holder filter must match for {$h}"
            );
        }
    }

    public function test_credit_and_unapproved_rows_are_excluded(): void
    {
        $t = Tenant::factory()->create();
        $this->seedCosts($t);
        $b = CashService::forTenant($t)->balances();

        // USD: +1000 funding − 300 purchase = 700 (999 credit + nothing else excluded)
        $this->assertSame(700.0, $b['USD']);
        // UGX: +500000 − 120000 = 380000 (pending 777 + void 888 excluded)
        $this->assertSame(380000.0, $b['UGX']);
        // SSP: +20000 − 5000 = 15000
        $this->assertSame(15000.0, $b['SSP']);
    }

    public function test_single_currency_balance_helper(): void
    {
        $t = Tenant::factory()->create();
        $this->seedCosts($t);
        $svc = CashService::forTenant($t);
        $this->assertSame(700.0, $svc->balance(['currency' => 'USD']));
        $this->assertSame(380000.0, $svc->balance(['currency' => 'UGX']));
    }

    public function test_signed_native_rule(): void
    {
        $this->assertSame(100.0, CashService::signedNative('cash_in', 100));
        $this->assertSame(100.0, CashService::signedNative('funding', 100));
        $this->assertSame(100.0, CashService::signedNative('customer_payment', 100));
        $this->assertSame(-100.0, CashService::signedNative('cash_out', 100));
        $this->assertSame(-100.0, CashService::signedNative('purchase', 100));
        $this->assertSame(-100.0, CashService::signedNative('supplier_payment', 100));
    }
}
