<?php
namespace App\Services\Knowledge;

use App\Models\KnowledgeFact;

/**
 * Read model the customer bot/storefront use to answer questions from durable Facts
 * (Policy / Facility / Schedule). Phase-1 retrieval is keyword→fact_type; richer phrasing is
 * Phase 3. Reads only current fact versions (is_current).
 */
class KnowledgeView
{
    /** Current value for a specific fact, or null. */
    public function fact(int $tenantId, string $factType, string $key): ?array
    {
        return KnowledgeFact::where('tenant_id', $tenantId)->where('fact_type', $factType)
            ->where('key', $key)->where('is_current', true)->value('value_json');
    }

    /** All current facts of a type → [key => value]. */
    public function ofType(int $tenantId, string $factType): array
    {
        return KnowledgeFact::where('tenant_id', $tenantId)->where('fact_type', $factType)
            ->where('is_current', true)->get()->mapWithKeys(fn ($f) => [$f->key => $f->value_json])->all();
    }

    /** Naive keyword router for customer questions → matching current facts. */
    public function answerFor(int $tenantId, string $question): array
    {
        $q = mb_strtolower($question);
        $map = [
            'delivery' => 'Policy', 'deliver' => 'Policy', 'payment' => 'Policy', 'cash' => 'Policy', 'card' => 'Policy',
            'parking' => 'Facility', 'wifi' => 'Facility', 'seating' => 'Facility',
            'open' => 'Schedule', 'closed' => 'Schedule', 'hours' => 'Schedule', 'holiday' => 'Schedule',
        ];
        foreach ($map as $kw => $type) {
            if (str_contains($q, $kw)) return $this->ofType($tenantId, $type);
        }
        return [];
    }
}
