<?php
namespace App\Services\Winworld;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use App\Models\WwIndent;
use App\Models\WwPlanning;
use App\Models\WwProductionEntry;

/**
 * Turns Alerts into WhatsApp messages over the tenant's Evolution instance,
 * reusing the NotifyOwner job and role-aware leadRecipients(). Thin on purpose
 * — all decisions live in the pure Alerts engine.
 */
class WinworldNotifier
{
    /** Event-driven: evaluate + dispatch alerts for a just-saved production entry. */
    public function fromEntry(WwIndent $indent, WwPlanning $planning, WwProductionEntry $entry): void
    {
        $t = $indent->tenant ?? Tenant::find($indent->tenant_id);
        if (! $t || ! (bool) $t->setting('ww_alerts_enabled', true)) return;

        $slow = (float) $t->setting('ww_slow_pct', Alerts::SLOW_DEFAULT);
        $ctx = [
            'indent_no'      => $indent->indent_no,
            'product'        => $indent->product_name,
            'machine'        => $planning->machine?->machine ?? ('#' . $planning->machine_id),
            'process'        => $planning->process,
            'stop_reason'    => $entry->stop_reason,
            'qc_result'      => $entry->qc_result,
            'efficiency_pct' => $entry->efficiency_pct,
        ];
        $alerts = Alerts::fromEntry($ctx, $slow);

        $delay = Alerts::delayRisk([
            'indent_no'     => $indent->indent_no,
            'product'       => $indent->product_name,
            'planned_end'   => optional($planning->planned_end)->format('Y-m-d'),
            'required_date' => optional($indent->required_date)->format('Y-m-d'),
        ]);
        if ($delay) $alerts[] = $delay;

        $this->dispatch($t, $alerts);
    }

    /** Send each alert to every recipient holding its target role. */
    public function dispatch(Tenant $t, array $alerts): void
    {
        if (! $alerts || ! $t->whatsapp_instance) return;
        foreach ($alerts as $a) {
            foreach ($t->leadRecipients($a['role']) as $r) {
                NotifyOwner::dispatch($t->id, $a['text'], $r['phone']);
            }
        }
    }

    /**
     * Scheduled sweep: alert on indents whose planned finish is past the
     * required date. Dedupes via delay_alerted_at (max once / 24h). Returns count.
     */
    public function scanDelays(Tenant $t): int
    {
        if (! (bool) $t->setting('ww_alerts_enabled', true)) return 0;

        $indents = WwIndent::with(['plannings'])
            ->whereNotNull('required_date')
            ->whereIn('status', ['Open','Planned','In Process'])
            ->get();

        $fired = 0;
        $cutoff = now()->subHours(24);
        foreach ($indents as $ind) {
            if ($ind->delay_alerted_at && $ind->delay_alerted_at->greaterThan($cutoff)) continue;

            $ends = $ind->plannings->pluck('planned_end')->filter()->map(fn($d) => $d->format('Y-m-d'))->all();
            if (! $ends) continue;
            $maxEnd = max($ends);

            $alert = Alerts::delayRisk([
                'indent_no'     => $ind->indent_no,
                'product'       => $ind->product_name,
                'planned_end'   => $maxEnd,
                'required_date' => $ind->required_date->format('Y-m-d'),
            ]);
            if ($alert) {
                $this->dispatch($t, [$alert]);
                $ind->delay_alerted_at = now();
                $ind->save();
                $fired++;
            }
        }
        return $fired;
    }
}
