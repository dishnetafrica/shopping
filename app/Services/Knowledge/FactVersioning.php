<?php
namespace App\Services\Knowledge;

/**
 * Pure rules for append-only, never-overwrite fact versioning. Persistence (BusinessMemory,
 * Phase-1 drop 2) uses these to stamp each new fact version and supersede the prior one.
 */
class FactVersioning
{
    /** Next version number given the current fact's version (null = first ever). */
    public static function nextVersion(?int $currentVersion): int
    {
        return $currentVersion === null ? 1 : $currentVersion + 1;
    }

    /** Should we write a new version? Only when the value actually changed. */
    public static function changed(?array $currentValue, array $newValue): bool
    {
        if ($currentValue === null) return true;
        return self::canon($currentValue) !== self::canon($newValue);
    }

    private static function canon(array $v): string
    {
        ksort($v);
        return json_encode($v);
    }
}
