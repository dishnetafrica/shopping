<?php
namespace App\Apps\Orders\Observatory;

use App\Apps\Orders\Experiment\DiffClassifier;
use App\Apps\Orders\Extract\EntityExtractor;
use App\Apps\Orders\MessageRouter;
use App\Models\OrderFlowMetric;
use App\Models\ShadowDiff;

/**
 * Shadow-mode experiment recorder. For every incoming customer message it records the router intent
 * and what the Draft parser WOULD have extracted (OrderFlowMetric), and — given the live cart
 * before/after the turn — compares the LIVE decision against the SHADOW decision and stores any
 * disagreement (ShadowDiff) with reason codes. Never changes the live reply (best-effort, isolated).
 */
class OrderObservatory
{
    public function __construct(
        private MessageRouter $router,
        private EntityExtractor $extractor,
        private DiffClassifier $differ,
    ) {}

    public function record(int $tenantId, ?int $conversationId, string $text, ?string $liveReply = null, array $liveBefore = [], array $liveAfter = []): OrderFlowMetric
    {
        $t0 = hrtime(true);
        $intent = $this->router->route($text);
        $isOrder = in_array($intent, [MessageRouter::ORDER, MessageRouter::ORDER_UPDATE], true);
        $bundle = $isOrder ? $this->extractor->extract($text) : null;
        $clarified = $liveReply !== null && $this->looksLikeClarification($liveReply);

        $metric = OrderFlowMetric::create([
            'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'intent' => $intent,
            'draft_parsed' => $isOrder, 'products_found' => $bundle ? count($bundle->products) : 0,
            'has_address' => (bool) ($bundle?->address ?? $bundle?->deliveryArea),
            'has_time' => (bool) ($bundle?->deliveryTime), 'has_payment' => (bool) ($bundle?->paymentMethod),
            'clarification' => $clarified, 'outcome' => $this->outcome($liveReply),
            'sample' => mb_substr($this->mask($text), 0, 200),
            'processing_ms' => (int) ((hrtime(true) - $t0) / 1e6), 'created_at' => now(),
        ]);

        // Experiment comparison: live (what the bot added this turn) vs shadow (what we extracted).
        if ($bundle) {
            $added = $this->addedThisTurn($liveBefore, $liveAfter);
            $diff = $this->differ->classify($added, $bundle);
            if ($diff['differs']) {
                $reasons = array_values(array_unique(array_filter(array_map(fn ($i) => $i['reason'], $diff['items']))));
                ShadowDiff::create([
                    'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                    'message' => mb_substr($this->mask($text), 0, 280),
                    'live_json' => $added, 'shadow_json' => array_map(fn ($p) => $p, $bundle->products),
                    'diff_json' => $diff['items'], 'reasons' => implode(',', $reasons),
                    'regression' => $diff['regressions'], 'status' => 'new', 'created_at' => now(),
                ]);
            }
        }
        return $metric;
    }

    /** Lines whose (name|weight) appear in the after-cart but not before — i.e. added this turn. */
    private function addedThisTurn(array $before, array $after): array
    {
        $key = fn ($l) => mb_strtolower((string) ($l['name'] ?? '')) . '|' . ($l['grams'] ?? $l['weight_grams'] ?? 'u');
        $had = [];
        foreach ($before as $l) $had[$key($l)] = true;
        $added = [];
        foreach ($after as $l) {
            if (! isset($had[$key($l)])) {
                $added[] = ['name' => (string) ($l['name'] ?? ''),
                            'weight_grams' => $l['grams'] ?? $l['weight_grams'] ?? null,
                            'quantity' => (int) ($l['qty'] ?? $l['quantity'] ?? 1)];
            }
        }
        return $added;
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
            str_contains($r, 'added')                                  => 'added_to_cart',
            $this->looksLikeClarification($reply)                      => 'clarify',
            str_contains($r, 'checkout') || str_contains($r, 'total')  => 'checkout',
            str_contains($r, 'menu')                                   => 'browse',
            default                                                    => 'other',
        };
    }

    private function mask(string $s): string { return preg_replace('/\d{6,}/', '######', $s) ?? $s; }
}
