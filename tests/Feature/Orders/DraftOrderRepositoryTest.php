<?php
namespace Tests\Feature\Orders;

use App\Apps\Orders\Dto\CartLine;
use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\DraftOrderRepository;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftOrderRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_draft_and_timeline_then_reloads(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $repo = new DraftOrderRepository();

        $draft = new DraftOrder(
            lines: [new CartLine('Fafda', weightGrams: 250, quantity: 1, unitPrice: 5000)],
            deliveryArea: 'Ntinda', paymentMethod: 'Mobile Money',
        );
        $repo->save($tenant->id, 42, $draft, [
            ['field' => 'line', 'value' => ['name' => 'Fafda', 'weight_grams' => 250]],
            ['field' => 'delivery_area', 'value' => 'Ntinda'],
        ]);

        $this->assertDatabaseHas('order_drafts', ['tenant_id' => $tenant->id, 'conversation_id' => 42, 'status' => 'open']);
        $this->assertSame(2, \App\Models\OrderDraftEvent::where('conversation_id', 42)->count());   // timeline

        $reloaded = $repo->forConversation($tenant->id, 42);
        $this->assertCount(1, $reloaded->lines);
        $this->assertSame(250, $reloaded->lines[0]->weightGrams);
        $this->assertSame('Ntinda', $reloaded->deliveryArea);

        $repo->markConfirmed($tenant->id, 42);
        $this->assertDatabaseHas('order_drafts', ['conversation_id' => 42, 'status' => 'confirmed']);
    }
}
