<?php
namespace App\Services\Winworld;

/**
 * SLA clock for the sales workflow. Computes when a stage is due and whether
 * it's ok / due-soon / overdue. Supports three SLA kinds:
 *   ['h'  => N] clock hours (e.g. "within 1 hour")
 *   ['wh' => N] working hours (uses the office calendar)
 *   ['wd' => N] working days  (= N × office day-hours on the calendar)
 * Working time uses an injected ShiftCalendar (default office: 08:00-17:00 Mon-Sat).
 */
final class SlaClock
{
    public const WARN_MINUTES = 30;

    public static function officeCalendar(): ShiftCalendar
    {
        return new ShiftCalendar(8, 17, [1, 2, 3, 4, 5, 6]); // Mon-Sat, 9h/day
    }

    public static function dueAt(\DateTimeInterface $start, array $sla, ?ShiftCalendar $cal = null): \DateTimeImmutable
    {
        $start = \DateTimeImmutable::createFromInterface($start);
        if (isset($sla['h']))  return $start->modify('+' . (int) round($sla['h'] * 60) . ' minutes');
        $cal ??= self::officeCalendar();
        if (isset($sla['wh'])) return $cal->addWorkingHours($start, (float) $sla['wh']);
        if (isset($sla['wd'])) return $cal->addWorkingHours($start, (float) $sla['wd'] * $cal->dailyHours());
        return $start; // no SLA
    }

    public static function minutesLeft(\DateTimeInterface $due, \DateTimeInterface $now): int
    {
        return (int) floor(($due->getTimestamp() - $now->getTimestamp()) / 60);
    }

    /** 'ok' | 'due_soon' | 'overdue' */
    public static function status(\DateTimeInterface $due, \DateTimeInterface $now): string
    {
        $left = self::minutesLeft($due, $now);
        if ($left < 0) return 'overdue';
        if ($left <= self::WARN_MINUTES) return 'due_soon';
        return 'ok';
    }
}
