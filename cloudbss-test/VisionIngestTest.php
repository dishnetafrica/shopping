<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\VisionBox;
use App\Models\VisionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-1 — the CloudBSS half of "an event actually arrives".
 *
 * Drop this into shopping-main at tests/Feature/VisionIngestTest.php and run
 * `php artisan test --filter=VisionIngest`.
 *
 * The gateway's own suite (tests/test_delivery.py) proves everything up to the socket:
 * that a confirmed shelf change becomes a real POST with the right headers and a body
 * that satisfies the validation rules PARSED OUT OF VisionEventController. What it
 * cannot prove from the other repository is Laravel itself — the route registration,
 * the token comparison, the tenant resolution and the row that comes out the far end.
 * That is this file's whole job.
 *
 * The payload below is a byte-for-byte capture of what events.py::build_payload emits.
 * If the gateway's contract changes, this test should be updated in the same commit.
 */
class VisionIngestTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly what the gateway sends. */
    private function gatewayPayload(array $over = []): array
    {
        return array_merge([
            'event'      => 'shelf.out_of_stock',
            'store'      => 'juba-main-01',
            'camera'     => 'Aisle 3',
            'zone'       => 'Sugar level 2',
            'from_state' => 'stocked',
            'to_state'   => 'empty',
            'confidence' => 0.88,
            'fill'       => 0.12,
            'thumbnail'  => base64_encode('not-really-a-jpeg'),
            'timestamp'  => '2026-08-11T09:20:00+0300',
        ], $over);
    }

    private function claimedBox(Tenant $t, string $token = 'box-token-abc'): VisionBox
    {
        return VisionBox::create([
            'box_uid'       => 'machine-id-0001',
            'secret_sha256' => hash('sha256', 'box-secret'),
            'status'        => 'claimed',
            'tenant_id'     => $t->id,
            'store'         => 'juba-main-01',
            'webhook_token' => $token,
            'claimed_at'    => now(),
        ]);
    }

    public function test_a_claimed_box_can_deliver_an_event(): void
    {
        $t = Tenant::factory()->create();
        $this->claimedBox($t);

        $r = $this->withHeader('X-Vision-Token', 'box-token-abc')
            ->postJson('/api/vision/events', $this->gatewayPayload());

        $r->assertOk()->assertJson(['ok' => true]);

        $ev = VisionEvent::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($ev, 'the event was accepted but no row was written');
        $this->assertSame($t->id, (int) $ev->tenant_id, 'event landed under the wrong tenant');
        $this->assertSame('shelf.out_of_stock', $ev->event);
        $this->assertSame('juba-main-01', $ev->store);
        $this->assertSame('Sugar level 2', $ev->zone);
        $this->assertSame('empty', $ev->to_state);
        $this->assertEqualsWithDelta(0.12, (float) $ev->fill, 0.0001);
    }

    public function test_the_base64_thumbnail_never_reaches_the_row(): void
    {
        $t = Tenant::factory()->create();
        $this->claimedBox($t);

        $this->withHeader('X-Vision-Token', 'box-token-abc')
            ->postJson('/api/vision/events', $this->gatewayPayload())->assertOk();

        $ev = VisionEvent::withoutGlobalScopes()->latest('id')->first();
        $this->assertArrayNotHasKey('thumbnail', (array) $ev->payload,
            'a base64 frame was stored in the payload column');
    }

    public function test_the_timestamp_the_gateway_sends_is_the_one_recorded(): void
    {
        $t = Tenant::factory()->create();
        $this->claimedBox($t);

        $this->withHeader('X-Vision-Token', 'box-token-abc')
            ->postJson('/api/vision/events', $this->gatewayPayload())->assertOk();

        $ev = VisionEvent::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('2026-08-11 06:20:00', (string) $ev->occurred_at->utc()->format('Y-m-d H:i:s'),
            'the +0300 offset was not honoured — an event was filed at the wrong time');
    }

    public function test_no_token_is_rejected(): void
    {
        $this->postJson('/api/vision/events', $this->gatewayPayload())->assertStatus(401);
        $this->assertSame(0, VisionEvent::withoutGlobalScopes()->count());
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $t = Tenant::factory()->create();
        $this->claimedBox($t);

        $this->withHeader('X-Vision-Token', 'not-the-token')
            ->postJson('/api/vision/events', $this->gatewayPayload())->assertStatus(401);
        $this->assertSame(0, VisionEvent::withoutGlobalScopes()->count());
    }

    public function test_a_revoked_box_can_no_longer_deliver(): void
    {
        $t = Tenant::factory()->create();
        $box = $this->claimedBox($t);
        $box->update(['status' => 'revoked']);

        $this->withHeader('X-Vision-Token', 'box-token-abc')
            ->postJson('/api/vision/events', $this->gatewayPayload())->assertStatus(401);
        $this->assertSame(0, VisionEvent::withoutGlobalScopes()->count());
    }

    public function test_every_field_the_gateway_sends_is_accepted_at_its_maximum(): void
    {
        $t = Tenant::factory()->create();
        $this->claimedBox($t);

        // The gateway takes camera and zone names from what staff typed in the editor.
        $r = $this->withHeader('X-Vision-Token', 'box-token-abc')
            ->postJson('/api/vision/events', $this->gatewayPayload([
                'camera' => str_repeat('c', 120),
                'zone'   => str_repeat('z', 120),
                'store'  => str_repeat('s', 80),
                'event'  => str_repeat('e', 60),
            ]));

        $r->assertOk();
        $this->assertSame(1, VisionEvent::withoutGlobalScopes()->count(),
            'a payload at the documented limits must insert, not throw on the column width');
    }
}
