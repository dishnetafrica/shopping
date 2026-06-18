<?php
namespace App\Services\Winworld;

/**
 * Read-side aggregation for the OEE dashboard. Pure logic over plain arrays
 * so it is fully unit-testable. Turns captured production entries + plannings
 * into the efficiency picture: per-machine OEE, downtime Pareto, machine board,
 * and headline summary.
 */
final class Analytics
{
    /** Downtime grouped by stop reason, worst (most hours) first. */
    public static function downtimePareto(array $entries): array
    {
        $by = [];
        foreach ($entries as $e) {
            $r = trim((string) ($e['stop_reason'] ?? ''));
            if ($r === '') continue;
            $by[$r] ??= ['reason' => $r, 'count' => 0, 'hours' => 0.0];
            $by[$r]['count']++;
            $by[$r]['hours'] += (float) ($e['actual_hours'] ?? 0);
        }
        $rows = array_values($by);
        usort($rows, fn($a, $b) => ($b['hours'] <=> $a['hours']) ?: ($b['count'] <=> $a['count']));
        foreach ($rows as &$r) $r['hours'] = round($r['hours'], 2);
        return $rows;
    }

    /**
     * Aggregate OEE for one machine's entries. Performance is time-weighted
     * against target rate. If plannedHours is unknown (<=0) availability falls
     * back to run hours (i.e. availability = 1) and OEE reflects P x Q only.
     */
    public static function machineOee(array $entries, float $plannedHours): array
    {
        $run = 0.0; $prod = 0.0; $scrap = 0.0; $targetWeighted = 0.0;
        foreach ($entries as $e) {
            $h = (float) ($e['actual_hours'] ?? 0);
            $run            += $h;
            $prod           += (float) ($e['produced_kg'] ?? 0);
            $scrap          += (float) ($e['scrap_kg'] ?? 0);
            $targetWeighted += (float) ($e['target_output_kg_hr'] ?? 0) * $h;
        }
        $actualRate = $run > 0 ? $prod / $run : 0.0;
        $targetRate = $run > 0 ? $targetWeighted / $run : 0.0;
        $planned    = $plannedHours > 0 ? $plannedHours : $run;

        return Oee::compute($run, $planned, $actualRate, $targetRate, $prod, $scrap) + [
            'run_hours'     => round($run, 2),
            'planned_hours' => round($planned, 2),
            'produced_kg'   => round($prod, 2),
            'scrap_kg'      => round($scrap, 2),
            'has_plan'      => $plannedHours > 0,
        ];
    }

    /** Per-machine next-available + booked hours in the next 7 days. */
    public static function machineBoard(array $plannings, \DateTimeInterface $now): array
    {
        $now  = \DateTimeImmutable::createFromInterface($now);
        $end7 = $now->modify('+7 days');
        $by = [];
        foreach ($plannings as $p) {
            $mid = $p['machine_id'] ?? null;
            if (! $mid) continue;
            $by[$mid] ??= ['machine_id' => $mid, 'next_available' => null, 'booked_hours_7d' => 0.0];

            if (! empty($p['planned_end'])) {
                $pe = new \DateTimeImmutable($p['planned_end']);
                $cur = $by[$mid]['next_available'];
                if ($cur === null || $pe > new \DateTimeImmutable($cur)) {
                    $by[$mid]['next_available'] = $pe->format('Y-m-d H:i');
                }
            }
            if (! empty($p['planned_start'])) {
                $ps = new \DateTimeImmutable($p['planned_start']);
                if ($ps >= $now && $ps <= $end7) {
                    $by[$mid]['booked_hours_7d'] += (float) ($p['required_hours'] ?? 0);
                }
            }
        }
        foreach ($by as &$b) $b['booked_hours_7d'] = round($b['booked_hours_7d'], 2);
        return array_values($by);
    }

    /** Headline KPIs across indents + entries. */
    public static function summary(array $indents, array $entries): array
    {
        $byStatus = ['Open' => 0, 'Planned' => 0, 'In Process' => 0, 'Completed' => 0, 'Closed' => 0];
        $orderKg = 0.0;
        foreach ($indents as $i) {
            $s = $i['status'] ?? 'Open';
            if (isset($byStatus[$s])) $byStatus[$s]++;
            $orderKg += (float) ($i['order_kg'] ?? 0);
        }
        $prod = 0.0; $scrap = 0.0; $effSum = 0.0; $effN = 0;
        foreach ($entries as $e) {
            $prod  += (float) ($e['produced_kg'] ?? 0);
            $scrap += (float) ($e['scrap_kg'] ?? 0);
            if (($e['efficiency_pct'] ?? null) !== null && $e['efficiency_pct'] !== '') {
                $effSum += (float) $e['efficiency_pct']; $effN++;
            }
        }
        return [
            'by_status'          => $byStatus,
            'order_kg'           => round($orderKg, 1),
            'produced_kg'        => round($prod, 1),
            'scrap_kg'           => round($scrap, 1),
            'avg_efficiency_pct' => $effN ? round($effSum / $effN, 1) : 0.0,
            'first_pass_yield'   => $prod > 0 ? round(($prod - $scrap) / $prod * 100, 1) : 0.0,
        ];
    }
}
