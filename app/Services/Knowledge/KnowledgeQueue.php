<?php
namespace App\Services\Knowledge;

use App\Services\Knowledge\Dto\ActionRequest;

/**
 * The queue between extraction and the change request. Phase-1 intelligence is deliberately
 * thin: collapse duplicate actions within one confirmation window by (action_type,target),
 * last-write-wins — so "Tea 5000 / 5500 / 6000" becomes a single action (6000).
 * Cross-message/source conflict resolution is Phase 3 (schema already carries source+time).
 */
class KnowledgeQueue
{
    /**
     * @param ActionRequest[] $actions
     * @return ActionRequest[] collapsed, original order of last occurrence preserved
     */
    public static function collapse(array $actions): array
    {
        $byKey = [];
        foreach ($actions as $a) {
            $byKey[$a->collapseKey()] = $a;     // later wins
        }
        return array_values($byKey);
    }
}
