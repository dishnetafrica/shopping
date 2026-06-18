<?php
namespace App\Services\Winworld;

/**
 * CMMS-lite response KPIs for the converter floor — the metrics that measure how
 * fast you fix what OEE flags:
 *   MTTR  = mean repair time (how fast you fix)
 *   MTBF  = operating hours / failures (how reliable the machine is)
 *   PM%   = preventive jobs done on/before due (how disciplined you are)
 * Pure logic over arrays of work orders. Dates are ISO strings.
 */
final class Maintenance
{
    /** Wrench time for a finished order, in minutes (started→completed, else reported→completed). */
    public static function repairMinutes(array $o): ?float
    {
        $start = $o['started_at'] ?? $o['reported_at'] ?? null;
        $end   = $o['completed_at'] ?? null;
        if (! $start || ! $end) return null;
        $m = (strtotime($end) - strtotime($start)) / 60;
        return $m >= 0 ? $m : null;
    }

    public static function mttr(array $orders): array
    {
        $tot = 0.0; $n = 0;
        foreach ($orders as $o) {
            if (($o['type'] ?? '') !== 'breakdown') continue;
            $m = self::repairMinutes($o);
            if ($m === null) continue;
            $tot += $m; $n++;
        }
        $mean = $n ? $tot / $n : 0.0;
        return ['count' => $n, 'minutes' => round($mean, 1), 'hours' => round($mean / 60, 2)];
    }

    public static function mtbf(float $operatingHours, int $failures): array
    {
        return [
            'failures'        => $failures,
            'operating_hours' => round($operatingHours, 1),
            'hours'           => $failures > 0 ? round($operatingHours / $failures, 1) : 0.0,
        ];
    }

    /** Preventive jobs that were due (by asOf) and whether they were done on time. */
    public static function pmCompliance(array $orders, ?string $asOf = null): array
    {
        $asOfTs = $asOf ? strtotime($asOf) : time();
        $due = 0; $onTime = 0;
        foreach ($orders as $o) {
            if (($o['type'] ?? '') !== 'preventive') continue;
            $dueAt = $o['due_at'] ?? null;
            if (! $dueAt || strtotime($dueAt) > $asOfTs) continue; // not due yet
            $due++;
            $done = $o['completed_at'] ?? null;
            if ($done && strtotime($done) <= strtotime($dueAt)) $onTime++;
        }
        return ['due' => $due, 'on_time' => $onTime, 'pct' => $due ? round($onTime / $due * 100, 1) : 100.0];
    }

    /** Worst-machine-first by downtime. */
    public static function byMachine(array $orders): array
    {
        $map = [];
        foreach ($orders as $o) {
            $mid = $o['machine_id'] ?? null;
            if (! $mid) continue;
            if (! isset($map[$mid])) $map[$mid] = ['machine_id' => $mid, 'machine' => $o['machine'] ?? ('#' . $mid), 'downtime_min' => 0.0, 'failures' => 0, 'open' => 0];
            $map[$mid]['downtime_min'] += (float) ($o['downtime_min'] ?? 0);
            if (($o['type'] ?? '') === 'breakdown') $map[$mid]['failures']++;
            if (($o['status'] ?? '') !== 'done') $map[$mid]['open']++;
        }
        $rows = array_values($map);
        usort($rows, fn($a, $b) => $b['downtime_min'] <=> $a['downtime_min']);
        return $rows;
    }

    public static function summary(array $orders, float $operatingHours, ?string $asOf = null): array
    {
        $failures = 0; $open = 0; $downtime = 0.0;
        foreach ($orders as $o) {
            if (($o['type'] ?? '') === 'breakdown') $failures++;
            if (($o['status'] ?? '') !== 'done') $open++;
            $downtime += (float) ($o['downtime_min'] ?? 0);
        }
        return [
            'open'         => $open,
            'breakdowns'   => $failures,
            'downtime_min' => round($downtime, 0),
            'downtime_hrs' => round($downtime / 60, 1),
            'mttr'         => self::mttr($orders),
            'mtbf'         => self::mtbf($operatingHours, $failures),
            'pm'           => self::pmCompliance($orders, $asOf),
        ];
    }
}
