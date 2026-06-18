<?php
namespace App\Services\Winworld;

/**
 * Status transitions for the production lifecycle. Pure logic.
 * Lifecycle: Open -> Planned -> In Process -> Completed -> Closed.
 */
final class StatusFlow
{
    public const ORDER = ['Open','Planned','In Process','Completed','Closed'];

    public static function rank(string $status): int
    {
        $i = array_search($status, self::ORDER, true);
        return $i === false ? -1 : $i;
    }

    /** A production entry's status from what was recorded. */
    public static function entryStatus(bool $hasEnd, ?string $stopReason): string
    {
        if ($stopReason !== null && $stopReason !== '') return 'Stopped';
        return $hasEnd ? 'Completed' : 'In Process';
    }

    /** A planning row's status given its entries. */
    public static function planningStatus(bool $anyStarted, bool $completed): string
    {
        if ($completed)  return 'Completed';
        if ($anyStarted) return 'In Process';
        return 'Planned';
    }

    /**
     * Roll an indent's status up from its process steps.
     * @param bool[] $stepCompleted completed flag per active process (empty = none planned)
     */
    public static function indentStatus(array $stepCompleted, bool $anyStarted, bool $anyPlanned): string
    {
        if ($stepCompleted !== [] && !in_array(false, $stepCompleted, true)) return 'Completed';
        if ($anyStarted) return 'In Process';
        if ($anyPlanned) return 'Planned';
        return 'Open';
    }

    /** Never move a status backwards by accident. */
    public static function advance(string $current, string $proposed): string
    {
        return self::rank($proposed) > self::rank($current) ? $proposed : $current;
    }
}
