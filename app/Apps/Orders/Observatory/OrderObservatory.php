<?php
namespace App\Apps\Orders\Observatory;

use App\Apps\Orders\Extract\EntityExtractor;
use App\Apps\Orders\MessageRouter;
use App\Models\OrderFlowMetric;

/**
 * Shadow-mode telemetry for the Order Engine: for every incoming customer message it records the
 * router intent and what the Draft parser WOULD have extracted, plus whether the live bot asked a
 * clarification — without changing the live reply. This produces the production numbers that prove
 * (or disprove) the router is reducing bad parses before any cutover.
 */
class OrderObservatory
{
    public function __construct(private MessageRouter $router, private EntityExtractor $extractor) {}

    public function record(int $tenantId, ?int $conversationId, string $text, ?string $liveReply = null): OrderFlowMetric
    {
        $t0 = hrtime(true);
        $intent = $this->router->route($text);
        $isOrder = in_array($intent, [MessageRouter::ORDER, MessageRouter::ORDER_UPDATE], true);

        $b = $isOrder ? $this->extractor->extract($text) : null;
        $ms = (int) ((hrtime(true) - $t0) / 1e6);

        return OrderFlowMetric::create([
            'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'intent' => $intent,
            'draft_parsed' => $isOrder, 'products_found' => $b ? count($b->products) : 0,
            'has_address' => (bool) ($b?->address ?? $b?->deliveryArea),
            'has_time' => (bool) ($b?->deliveryTime), 'has_payment' => (bool) ($b?->paymentMethod),
            'clarification' => $liveReply !== null && $this->looksLikeClarification($liveReply),
            'outcome' => $this->outcome($liveReply), 'sample' => mb_substr($this->mask($text), 0, 200),
            'processing_ms' => $ms, 'created_at' => now(),
        ]);
    }

    private function looksLikeClarification(string $reply): bool
    {
        return (bool) preg_match('/\b(did you mean|which one|can you clarify|do you mean|couldn.?t find|not sure)\b/i', $reply);
    }

    private function outcome(?string $reply): ?string
    {
        if ($reply === null) return null;
        $r = mb_strtolower($reply);
        return match (true) {
            str_contains($r, 'added')                          => 'added_to_cart',
            $this->looksLikeClarification($reply)              => 'clarify',
            str_contains($r, 'checkout') || str_contains($r, 'total') => 'checkout',
            str_contains($r, 'menu')                           => 'browse',
            default                                            => 'other',
        };
    }

    private function mask(string $s): string { return preg_replace('/\d{6,}/', '######', $s) ?? $s; }
}
