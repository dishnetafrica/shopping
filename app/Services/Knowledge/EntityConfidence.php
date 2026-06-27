<?php
namespace App\Services\Knowledge;

/** Helpers for per-entity confidence (so Phase-3 AI can trust a price but question a meal tag). */
class EntityConfidence
{
    public static function entity(string $field, $value, float $confidence): array
    {
        return ['field' => $field, 'value' => $value, 'confidence' => round($confidence, 3)];
    }

    /** Conservative rollup = the weakest entity (a chain is as strong as its weakest link). */
    public static function rollup(array $entities): float
    {
        $vals = array_map(fn ($e) => (float) ($e['confidence'] ?? 1.0), $entities);
        return $vals ? round(min($vals), 3) : 1.0;
    }
}
