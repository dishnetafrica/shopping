<?php

namespace Tests\Feature\Travel;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Models\User;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Enquiry\TravelPipeline;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Travel Vertical · M1 — the V3 Trips workspace over HTTP: the capability catalogue includes it, the
 * list and detail show the trip, the staff commands obey the pipeline and write the timeline, a role
 * without the permission is refused, and a signed-in user of one agency cannot read or command
 * another agency's trip.
 */
class TravelWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NotifyOwner::class]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function agency(string $vertical = 'travel'): Tenant
    {
        return Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'timezone' => 'Africa/Kampala']]);
    }

    private function user(Tenant $t, string $role = 'owner', string $name = 'Owner'): User
    {
        return User::create([
            'tenant_id' => $t->id, 'name' => $name, 'role' => $role,
            'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
    }

    private function signIn(User $u): void
    {
        app(TenantContext::class)->set($u->tenant_id);
        $this->actingAs($u);
    }

    /** A complete trip for this agency, created the way a conversation creates it. */
    private function trip(Tenant $t, string $phone = '256700111222'): TravelEnquiry
    {
        app(TenantContext::class)->set($t->id);
        $c = Conversation::create(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'main', 'state' => [], 'cart' => []]);
        $out = now('Africa/Kampala')->addDays(30)->format('Y-m-d');
        app(TravelTurn::class)->afterReply($t, $c, $phone, 'whatsapp', 'one way to Dubai for 2',
            "Noted.\n<<TRIP {\"from\":\"Entebbe\",\"to\":\"Dubai\",\"trip_type\":\"one_way\",\"depart_date\":\"$out\",\"adults\":2}>>");

        return TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $t->id)->latest('id')->firstOrFail();
    }

    public function test_catalogue_includes_trips_and_the_list_shows_the_enquiry(): void
    {
        $t = $this->agency();
        $e = $this->trip($t);
        $this->signIn($this->user($t));

        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'trips'));

        $j = $this->getJson('/api/v1/workspace/trips')->assertOk()->json();
        $row = collect($j['table']['rows'] ?? [])->first(fn ($r) => str_contains((string) ($r['link'] ?? ''), '/workspace/trip/' . $e->id));
        $this->assertNotNull($row);
        $this->assertSame('Entebbe → Dubai', $row['trip']['value']);
        $this->assertSame('2 adults', $row['party']['value']);
        $this->assertSame('Details collected', $row['stage']['value']);
        $this->assertSame('Unassigned', $row['owner']['value']);
        $ready = collect($j['financials'] ?? [])->firstWhere('key', 'ready');
        $this->assertSame('1', $ready['value']);
    }

    public function test_detail_shows_the_trip_its_timeline_and_the_staff_commands(): void
    {
        $t = $this->agency();
        $e = $this->trip($t);
        $this->signIn($this->user($t));

        $j = $this->getJson('/api/v1/workspace/trip/' . $e->id)->assertOk()->json();
        $this->assertSame('Details collected', $j['headline']['value']);
        $text = json_encode($j['answers'] ?? []);
        $this->assertStringContainsString('Dubai, United Arab Emirates', $text);
        $this->assertStringContainsString('ready to price', $text);
        $keys = collect($j['actions'] ?? [])->pluck('key');
        foreach (['open_chat', 'set_stage', 'mark_lost', 'assign_owner', 'add_note', 'flag_ai'] as $k) {
            $this->assertTrue($keys->contains($k), "missing action $k");
        }
        $types = collect($j['timeline'] ?? [])->pluck('type');
        $this->assertTrue($types->contains('created'));
        $this->assertTrue($types->contains('stage_changed'));
    }

    public function test_commands_obey_the_pipeline_and_write_the_timeline(): void
    {
        $t = $this->agency();
        $e = $this->trip($t);
        $owner = $this->user($t);
        $agent = $this->user($t, 'sales', 'Sarah');
        $this->signIn($owner);
        $base = '/api/v1/workspace/trip/' . $e->id;

        // A move the pipeline does not allow is refused and changes nothing.
        $this->postJson($base . '/set_stage', ['stage' => 'TICKET_ISSUED'])->assertOk()->assertJson(['ok' => false, 'error' => 'move_not_allowed']);
        $this->assertSame(TravelPipeline::DETAILS_COLLECTED, $e->fresh()->stage);
        $this->postJson($base . '/set_stage', ['stage' => 'NONSENSE'])->assertOk()->assertJson(['ok' => false, 'error' => 'bad_stage']);

        // An allowed move.
        $this->postJson($base . '/set_stage', ['stage' => 'QUOTATION_SENT', 'reason' => 'Quoted by phone'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(TravelPipeline::QUOTATION_SENT, $e->fresh()->stage);

        // Owner assignment: only this agency's own staff.
        $this->postJson($base . '/assign_owner', ['owner' => $agent->id . ' — Sarah'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame((int) $agent->id, (int) $e->fresh()->owner_user_id);
        $outsider = $this->user($this->agency(), 'owner', 'Outsider');
        $this->postJson($base . '/assign_owner', ['owner' => $outsider->id . ' — Outsider'])->assertOk()->assertJson(['ok' => false, 'error' => 'unknown_staff']);
        $this->assertSame((int) $agent->id, (int) $e->fresh()->owner_user_id);

        $this->postJson($base . '/add_note', ['note' => 'Prefers morning flights'])->assertOk()->assertJson(['ok' => true]);
        $this->postJson($base . '/flag_ai', ['category' => 'wrong_detail', 'note' => 'It was 3 adults'])->assertOk()->assertJson(['ok' => true]);

        // Lost needs a reason.
        $this->postJson($base . '/mark_lost', [])->assertOk()->assertJson(['ok' => false, 'error' => 'reason_required']);
        $this->postJson($base . '/mark_lost', ['reason' => 'Booked elsewhere'])->assertOk()->assertJson(['ok' => true]);
        $e->refresh();
        $this->assertSame(TravelPipeline::LOST, $e->stage);
        $this->assertNotNull($e->closed_at);
        $this->assertSame('Booked elsewhere', $e->lost_reason);

        app(TenantContext::class)->set($t->id);
        $types = TravelEnquiryEvent::where('enquiry_id', $e->id)->pluck('type')->all();
        foreach (['owner_assigned', 'note', 'ai_flagged'] as $type) {
            $this->assertContains($type, $types);
        }
        $staffMoves = TravelEnquiryEvent::where('enquiry_id', $e->id)->where('type', 'stage_changed')->where('actor', 'staff')->get();
        $this->assertSame(2, $staffMoves->count());
        $this->assertSame((int) $owner->id, (int) $staffMoves->first()->actor_user_id, 'the timeline records who did it');
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $t = $this->agency();
        $e = $this->trip($t);
        $this->signIn($this->user($t, 'warehouse', 'Stores'));      // warehouse has no Customers access

        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/set_stage', ['stage' => 'QUOTATION_SENT'])
            ->assertJson(['ok' => false, 'error' => 'forbidden']);
        $this->assertSame(TravelPipeline::DETAILS_COLLECTED, $e->fresh()->stage);
    }

    public function test_one_agency_cannot_read_or_command_another_agencys_trip(): void
    {
        $a = $this->agency();
        $e = $this->trip($a);
        $b = $this->agency();
        $this->signIn($this->user($b));

        $j = $this->getJson('/api/v1/workspace/trip/' . $e->id)->assertOk()->json();
        $this->assertSame('not_found', $j['meta']['error'] ?? null);
        $this->assertStringNotContainsString('Dubai', json_encode($j));

        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/mark_lost', ['reason' => 'x'])->assertOk()->assertJson(['ok' => false, 'error' => 'not_found']);
        $rows = $this->getJson('/api/v1/workspace/trips')->assertOk()->json('table.rows');
        $this->assertSame([], $rows);

        app(TenantContext::class)->set($a->id);
        $this->assertSame(TravelPipeline::DETAILS_COLLECTED, TravelEnquiry::find($e->id)->stage, 'agency A\'s trip is untouched');
    }

    public function test_a_business_that_is_not_a_travel_agency_gets_an_empty_screen_and_no_commands(): void
    {
        $shop = $this->agency('grocery');
        $this->signIn($this->user($shop));

        $j = $this->getJson('/api/v1/workspace/trips')->assertOk()->json();
        $this->assertSame([], $j['table']['rows'] ?? null);
        $this->assertSame('Available for travel agencies', $j['object']['subtitle'] ?? null);
        $this->postJson('/api/v1/workspace/trip/1/add_note', ['note' => 'x'])->assertOk()->assertJson(['ok' => false, 'error' => 'not_available']);
    }
}
