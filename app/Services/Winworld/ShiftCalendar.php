<?php
namespace App\Services\Winworld;

/**
 * Shift-aware scheduling. Production runs inside a daily working window
 * (default 07:00-19:00 = 12h/day, 7 days/week -> 84h/machine/week,
 * matching the planning workbook). Computes a planned_end that respects
 * the window: work spills to the next day's window, it does not run
 * through the night.
 *
 * Single shift by default. Two-shift / holiday calendar is a later
 * refinement (design Q6/Q11) handled by widening the window or skipping days.
 */
final class ShiftCalendar
{
    private int $startHour;
    private int $endHour;
    /** @var array<string,bool> ISO 'Y-m-d' => non-working */
    private array $holidays;
    private array $workingDow; // 1=Mon..7=Sun

    /**
     * @param int[] $workingDow days of week that run (default all 7)
     * @param string[] $holidays 'Y-m-d' dates skipped
     */
    public function __construct(int $startHour = 7, int $endHour = 19, array $workingDow = [1,2,3,4,5,6,7], array $holidays = [])
    {
        if ($endHour <= $startHour) throw new \InvalidArgumentException('endHour must be after startHour');
        $this->startHour = $startHour;
        $this->endHour   = $endHour;
        $this->workingDow = $workingDow;
        $this->holidays = array_fill_keys($holidays, true);
    }

    public function dailyHours(): int { return $this->endHour - $this->startHour; }

    private function isWorkingDay(\DateTimeImmutable $d): bool
    {
        if (isset($this->holidays[$d->format('Y-m-d')])) return false;
        return in_array((int)$d->format('N'), $this->workingDow, true);
    }

    private function windowStart(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->setTime($this->startHour, 0, 0);
    }
    private function windowEnd(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->setTime($this->endHour, 0, 0);
    }

    /** Move a timestamp forward to the next valid moment inside a working window. */
    public function normalize(\DateTimeImmutable $t): \DateTimeImmutable
    {
        $guard = 0;
        while ($guard++ < 3660) {
            if ($this->isWorkingDay($t)) {
                $ws = $this->windowStart($t);
                $we = $this->windowEnd($t);
                if ($t < $ws) return $ws;
                if ($t < $we) return $t;
            }
            // advance to next day's window start
            $t = $this->windowStart($t->modify('+1 day'));
        }
        throw new \RuntimeException('normalize: no working day found');
    }

    /**
     * Add working hours to a start time, respecting the daily window.
     * Returns the planned end timestamp.
     */
    public function addWorkingHours(\DateTimeImmutable $start, float $hours): \DateTimeImmutable
    {
        $cursor = $this->normalize($start);
        if ($hours <= 0) return $cursor;

        $remaining = (int) round($hours * 3600); // seconds
        $guard = 0;
        while ($remaining > 0 && $guard++ < 3660) {
            $we = $this->windowEnd($cursor);
            $availToday = $we->getTimestamp() - $cursor->getTimestamp();
            if ($remaining <= $availToday) {
                return $cursor->modify("+{$remaining} seconds");
            }
            $remaining -= $availToday;
            // jump to next working window start
            $cursor = $this->normalize($this->windowStart($cursor->modify('+1 day')));
        }
        return $cursor;
    }

    /** Working hours actually available between two timestamps (for planned_time). */
    public function workingHoursBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        if ($to <= $from) return 0.0;
        $secs = 0; $cursor = $this->normalize($from); $guard = 0;
        while ($cursor < $to && $guard++ < 3660) {
            $we = $this->windowEnd($cursor);
            $segEnd = $we < $to ? $we : $to;
            $secs += max(0, $segEnd->getTimestamp() - $cursor->getTimestamp());
            $cursor = $this->normalize($this->windowStart($cursor->modify('+1 day')));
        }
        return $secs / 3600;
    }
}
