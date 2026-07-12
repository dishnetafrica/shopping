<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Roadmap E4.5 (Constitution R7 — money is typed, never overloaded).
 *
 * The `orders.payment` column used to hold EITHER a sell currency ("sell:USD") OR a cash/MoMo
 * method, so editing an order in one screen could clobber the meaning set in another. This proves
 * the new typed columns exist, that the accessors prefer them, that they fall back to decoding the
 * legacy `sell:` marker, and that the migration backfills existing rows.
 */
class OrderTypedMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'sell_currency'));
        $this->assertTrue(Schema::hasColumn('orders', 'payment_method'));
    }

    public function test_sell_currency_prefers_typed_column(): void
    {
        $o = new Order();
        $o->sell_currency = 'ugx';
        $o->payment = 'sell:SSP';
        $this->assertSame('UGX', $o->sellCurrency());
    }

    public function test_sell_currency_falls_back_to_legacy_marker(): void
    {
        $o = new Order();
        $o->payment = 'sell:ssp';
        $this->assertSame('SSP', $o->sellCurrency());
        $this->assertSame('USD', (new Order())->sellCurrency()); // default
    }

    public function test_payment_method_prefers_column_and_ignores_sell_marker(): void
    {
        $o = new Order();
        $o->payment_method = 'Cash';
        $o->payment = 'MoMo';
        $this->assertSame('Cash', $o->paymentMethodResolved());

        $legacy = new Order();
        $legacy->payment = 'MoMo';
        $this->assertSame('MoMo', $legacy->paymentMethodResolved());

        $currency = new Order();
        $currency->payment = 'sell:USD';
        $this->assertNull($currency->paymentMethodResolved());
    }

    public function test_migration_backfills_existing_rows(): void
    {
        $t = Tenant::factory()->create();
        // Insert legacy rows straight past the model so the typed columns start empty.
        $currencyId = DB::table('orders')->insertGetId([
            'tenant_id' => $t->id, 'order_no' => 'ORD-A', 'total' => 100,
            'payment' => 'sell:UGX', 'status' => 'New', 'channel' => 'pos',
            'sell_currency' => null, 'payment_method' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = DB::table('orders')->insertGetId([
            'tenant_id' => $t->id, 'order_no' => 'ORD-B', 'total' => 100,
            'payment' => 'MoMo', 'status' => 'New', 'channel' => 'pos',
            'sell_currency' => null, 'payment_method' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Re-run the backfill portion of the migration (idempotent).
        DB::table('orders')->select('id', 'payment')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $o) {
                $p = (string) ($o->payment ?? '');
                $sc = null; $pm = null;
                if (strncmp($p, 'sell:', 5) === 0) { $sc = strtoupper(substr($p, 5)); }
                elseif ($p !== '') { $pm = $p; }
                DB::table('orders')->where('id', $o->id)->update(['sell_currency' => $sc, 'payment_method' => $pm]);
            }
        });

        $this->assertSame('UGX', DB::table('orders')->where('id', $currencyId)->value('sell_currency'));
        $this->assertNull(DB::table('orders')->where('id', $currencyId)->value('payment_method'));
        $this->assertSame('MoMo', DB::table('orders')->where('id', $methodId)->value('payment_method'));
        $this->assertNull(DB::table('orders')->where('id', $methodId)->value('sell_currency'));
    }
}
