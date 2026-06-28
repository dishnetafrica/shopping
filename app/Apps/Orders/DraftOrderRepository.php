<?php
namespace App\Apps\Orders;

use App\Apps\Orders\Dto\DraftOrder;
use App\Models\OrderDraft;
use App\Models\OrderDraftEvent;
use Illuminate\Support\Facades\DB;

/** Loads/persists the per-conversation draft and appends timeline events. */
class DraftOrderRepository
{
    public function forConversation(int $tenantId, int $conversationId): DraftOrder
    {
        $row = OrderDraft::where('tenant_id', $tenantId)->where('conversation_id', $conversationId)
            ->where('status', 'open')->first();
        return $row && $row->payload_json ? DraftOrder::fromArray($row->payload_json) : new DraftOrder();
    }

    /** @param array<int,array{field:string,value:mixed}> $events */
    public function save(int $tenantId, int $conversationId, DraftOrder $draft, array $events = []): OrderDraft
    {
        return DB::transaction(function () use ($tenantId, $conversationId, $draft, $events) {
            $row = OrderDraft::updateOrCreate(
                ['tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'status' => 'open'],
                ['payload_json' => $draft->toArray()],
            );
            foreach ($events as $e) {
                OrderDraftEvent::create([
                    'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'draft_id' => $row->id,
                    'field' => $e['field'], 'value_json' => ['value' => $e['value']], 'created_at' => now(),
                ]);
            }
            return $row;
        });
    }

    public function markConfirmed(int $tenantId, int $conversationId): void
    {
        OrderDraft::where('tenant_id', $tenantId)->where('conversation_id', $conversationId)
            ->where('status', 'open')->update(['status' => 'confirmed']);
    }
}
