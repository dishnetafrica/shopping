<?php
namespace Tests\Feature\Bot;

use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Services\Bot\Pricing\CurrencyPricer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Export/USD quoting — encodes the EuroPearl ORD/quote production bug as a regression contract. */
class CurrencyPricerTest extends TestCase
{
    use RefreshDatabase;

    private CurrencyPricer $pricer;
    private Tenant $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricer = app(CurrencyPricer::class);
        $this->t = Tenant::factory()->create();
        $this->t->putSetting('usdUgx', 3675);
        $this->t->putSetting('ssMarkupPct', 25);
        app(TenantContext::class)->set($this->t->id);
    }

    private function quote(int $qty = 200, float $price = 70000): array
    {
        return ['lines' => [['name' => 'A4 Photocopier Paper', 'qty' => $qty, 'price' => $price, 'sum' => $price * $qty, 'matched' => true]],
                'total' => $price * $qty, 'currency' => 'UGX'];
    }

    public function test_europearl_replay_case(): void
    {
        $case = json_decode(file_get_contents(base_path('tests/Fixtures/quote_pricing_replays.json')), true)[0];
        $this->t->putSetting('usdUgx', $case['tenant_settings']['usdUgx']);
        $this->t->putSetting('ssMarkupPct', $case['tenant_settings']['ssMarkupPct']);

        $res = $this->pricer->toUsd($this->t, '211900000000', $this->quote($case['line']['qty'], $case['line']['ugx_price']));
        $this->assertSame('USD', $res['quote']['currency']);
        $this->assertEqualsWithDelta($case['expected']['unit_usd'], $res['quote']['lines'][0]['price'], 0.001);
        $this->assertEqualsWithDelta($case['expected']['total_usd'], $res['quote']['total'], 0.001);
        $this->assertTrue($this->pricer->mentionsSouthSudan($case['customer_text']));
    }

    public function test_plain_usd_no_markup(): void
    {
        $this->t->putSetting('ssMarkupPct', 0);
        $res = $this->pricer->toUsd($this->t, '256700000000', $this->quote());
        $this->assertEqualsWithDelta(19.0, $res['quote']['lines'][0]['price'], 0.001);
        $this->assertEqualsWithDelta(3800.0, $res['quote']['total'], 0.001);
    }

    public function test_per_client_markup_override(): void
    {
        CustomerProfile::create(['tenant_id' => $this->t->id, 'phone' => '256711111111', 'ss_markup_pct' => 10]);
        $this->assertEqualsWithDelta(10.0, $this->pricer->ssMarkupFor($this->t, '256711111111'), 0.001);
        // tenant default (25) still applies for an unknown customer
        $this->assertEqualsWithDelta(25.0, $this->pricer->ssMarkupFor($this->t, '256722222222'), 0.001);
    }

    public function test_detection(): void
    {
        $this->assertTrue($this->pricer->wantsUsd('How much is the price in USD'));
        $this->assertTrue($this->pricer->wantsUsd('I need price in USD for export'));
        $this->assertFalse($this->pricer->wantsUsd('Like 200'));
        $this->assertTrue($this->pricer->shouldQuoteUsd($this->t, '211900000000', 'hi'));   // +211 phone
    }

    public function test_signature_is_stable_and_sensitive(): void
    {
        $a = $this->pricer->signature($this->quote(200));
        $b = $this->pricer->signature($this->quote(200));
        $c = $this->pricer->signature($this->quote(201));
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
