<?php

namespace Tests\Feature;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Finance\CashbookExport;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the "empty morning cashbook" bug: a scheduled command has no TenantContext, so OrderCost's
 * global tenant scope appends `AND tenant_id = NULL` and the export reads $0.00. The digest command
 * must set the context per tenant — this test proves the export is blank without it and correct with it.
 */
class CashbookDigestScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $tid;

    protected function setUp(): void
    {
        parent::setUp();
        $t = Tenant::factory()->create();
        $this->tid = $t->id;

        // Two cash movements: +$100 in, −$40 out  →  combined $60.
        OrderCost::create(['tenant_id' => $this->tid, 'stage' => 'cash_in', 'pay_status' => 'cash',
            'currency' => 'USD', 'native_amount' => 100, 'paid_native' => 100, 'rate_to_usd' => 1, 'usd_amount' => 100, 'status' => 'approved']);
        OrderCost::create(['tenant_id' => $this->tid, 'stage' => 'expense', 'pay_status' => 'cash',
            'currency' => 'USD', 'native_amount' => 40, 'paid_native' => 40, 'rate_to_usd' => 1, 'usd_amount' => 40, 'status' => 'approved']);
    }

    public function test_export_reads_zero_without_tenant_context(): void
    {
        app(TenantContext::class)->clear();   // simulate a raw console run (the bug)
        $data = app(CashbookExport::class)->collect(Tenant::withoutGlobalScopes()->find($this->tid));
        $this->assertSame(0.0, $data['combinedUsd']);
        $this->assertEmpty($data['holders']);
    }

    public function test_export_is_correct_with_tenant_context(): void
    {
        app(TenantContext::class)->set($this->tid);   // what the fixed command now does
        $data = app(CashbookExport::class)->collect(Tenant::find($this->tid));
        $this->assertEqualsWithDelta(60.0, $data['combinedUsd'], 0.01);
    }
}
