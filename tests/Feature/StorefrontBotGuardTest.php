<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Abuse guard on the public storefront order/quote endpoints (StorefrontController::botGuard):
 *  - a filled honeypot field is treated as a bot and gets a fake success (no work done);
 *  - repeated submits from one IP are rate-limited (429) even with an empty honeypot.
 */
class StorefrontBotGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::factory()->create(['slug' => 'eaparts']);
        RateLimiter::clear('sforder:' . $this->t->id . ':127.0.0.1');
        RateLimiter::clear('sfquote:' . $this->t->id . ':127.0.0.1');
    }

    public function test_filled_honeypot_is_silently_accepted_without_creating_an_order(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('orders')->count();

        $this->postJson('/eaparts/order', [
            'name' => 'Bot', 'phone' => '211900000000', 'website' => 'http://spam.example',
            'items' => [['name' => 'Clutch Plate', 'qty' => 1, 'price' => 45000]],
        ])->assertOk()->assertJsonPath('ok', true);

        // Fake success: no order actually created.
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('orders')->count());
    }

    public function test_rapid_submits_are_rate_limited(): void
    {
        // The guard runs before body validation, so an empty cart returns 422 while under the
        // limit and 429 once the per-IP counter (12/min) is exhausted.
        $seen429 = false;
        for ($i = 0; $i < 14; $i++) {
            $r = $this->postJson('/eaparts/quote', ['name' => '', 'items' => []]);
            if ($r->getStatusCode() === 429) { $seen429 = true; break; }
        }
        $this->assertTrue($seen429, 'expected a 429 after exceeding the submit rate limit');
    }
}
