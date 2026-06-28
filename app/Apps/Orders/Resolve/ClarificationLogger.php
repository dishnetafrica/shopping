<?php
namespace App\Apps\Orders\Resolve;

use App\Models\ResolutionClarification;

/**
 * Turns production clarifications into catalogue improvements. Every time the resolver asks, we log
 * the query + candidates; when the customer picks, we record the choice. Repeated query→choice
 * patterns (e.g. 100 customers type "Vanela" and all choose Vanela Gathiya) become alias proposals.
 */
class ClarificationLogger
{
    public function logClarify(int $tenantId, ?int $conversationId, ProductResolution $res): int
    {
        return (int) ResolutionClarification::create([
            'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
            'query' => $res->query, 'candidates_json' => $res->candidates, 'created_at' => now(),
        ])->id;
    }

    public function recordChoice(int $clarificationId, ?int $chosenProductId, ?string $customerReply): void
    {
        ResolutionClarification::where('id', $clarificationId)
            ->update(['chosen_product_id' => $chosenProductId, 'customer_reply' => $customerReply]);
    }

    /** Query→choice patterns strong enough to propose as aliases (for human review). */
    public function aliasProposals(int $tenantId, int $minCount = 10): array
    {
        return ResolutionClarification::where('tenant_id', $tenantId)->whereNotNull('chosen_product_id')
            ->selectRaw('LOWER(query) as q, chosen_product_id, COUNT(*) as n')
            ->groupBy('q', 'chosen_product_id')->havingRaw('COUNT(*) >= ?', [$minCount])
            ->orderByDesc('n')->get()
            ->map(fn ($r) => ['query' => $r->q, 'product_id' => $r->chosen_product_id, 'count' => (int) $r->n])->all();
    }
}
