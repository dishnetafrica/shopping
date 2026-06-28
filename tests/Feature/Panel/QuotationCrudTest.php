<?php
namespace Tests\Feature\Panel;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Read / edit / delete a saved quotation from the panel — the CRUD gap behind "can't see or edit". */
class QuotationCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $this->t->id]);
        $this->actingAs($u);
    }

    private function quote(string $status = 'sent'): Quotation
    {
        $q = Quotation::create(['tenant_id' => $this->t->id, 'quote_no' => 'Q-' . uniqid(),
            'customer_name' => 'RA Enterprises', 'customer_phone' => '211984456789',
            'currency' => 'USD', 'total' => 3800, 'status' => $status, 'source' => 'bot']);
        QuotationItem::create(['quotation_id' => $q->id, 'tenant_id' => $this->t->id,
            'name' => 'A4 Photocopier Paper', 'qty' => 200, 'unit_price' => 19, 'line_total' => 3800, 'matched' => true]);
        return $q;
    }

    public function test_show_returns_the_line_items(): void
    {
        $q = $this->quote();
        $this->get("/papi/quotation-show?id={$q->id}")
            ->assertOk()
            ->assertJsonPath('quote.items.0.name', 'A4 Photocopier Paper')
            ->assertJsonPath('quote.items.0.qty', 200)
            ->assertJsonPath('quote.total', 3800);
    }

    public function test_update_recomputes_total_and_replaces_items(): void
    {
        $q = $this->quote();
        $items = json_encode([['name' => 'A4 Photocopier Paper', 'qty' => 100, 'unit_price' => 19]]);
        $this->post("/papi/quotation-update", ['id' => $q->id, 'currency' => 'USD', 'items' => $items])
            ->assertOk()
            ->assertJsonPath('total', 1900);

        $this->assertSame(1, QuotationItem::where('quotation_id', $q->id)->count());
        $this->assertEqualsWithDelta(1900, (float) $q->fresh()->total, 0.001);
    }

    public function test_delete_removes_quote_and_items(): void
    {
        $q = $this->quote();
        $this->post("/papi/quotation-delete", ['id' => $q->id])->assertOk()->assertJsonPath('deleted', true);
        $this->assertNull(Quotation::find($q->id));
        $this->assertSame(0, QuotationItem::where('quotation_id', $q->id)->count());
    }

    public function test_converted_quote_cannot_be_edited_or_deleted(): void
    {
        $q = $this->quote('converted');
        $items = json_encode([['name' => 'X', 'qty' => 1, 'unit_price' => 1]]);
        $this->post("/papi/quotation-update", ['id' => $q->id, 'items' => $items])->assertStatus(422);
        $this->post("/papi/quotation-delete", ['id' => $q->id])->assertStatus(422);
        $this->assertNotNull(Quotation::find($q->id));
    }

    public function test_tenant_isolation(): void
    {
        $other = Tenant::factory()->create();
        $q = Quotation::create(['tenant_id' => $other->id, 'quote_no' => 'Q-X', 'customer_phone' => '1',
            'currency' => 'UGX', 'total' => 1, 'status' => 'sent', 'source' => 'bot']);
        $this->get("/papi/quotation-show?id={$q->id}")->assertStatus(404);
    }
}
