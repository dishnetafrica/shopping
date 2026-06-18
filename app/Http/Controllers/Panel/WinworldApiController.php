<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwIndent;
use App\Models\WwMachine;
use App\Models\WwPlanning;
use App\Models\WwProductionEntry;
use App\Services\Winworld\StatusFlow;
use Illuminate\Http\Request;

/**
 * JSON API for the Win World Production Entry screen. Tenant scoping is
 * automatic via BelongsToTenant. Writes use the panel's GET convention.
 */
class WinworldApiController extends Controller
{
    /** Active machines for the picker. */
    public function machines(Request $request)
    {
        $rows = WwMachine::query()->where('active', true)
            ->orderBy('process')->orderBy('machine')
            ->get(['id','process','machine','max_speed','speed_type']);
        return response()->json(['ok' => true, 'machines' => $rows]);
    }

    /** Open jobs (planned / running) an operator can record against. */
    public function jobs(Request $request)
    {
        $machineId = (int) $request->query('machine_id', 0);

        $q = WwPlanning::query()
            ->whereIn('status', ['Planned','In Process'])
            ->whereHas('indent', fn($i) => $i->whereIn('status', ['Planned','In Process']))
            ->with(['indent:id,indent_no,product_name,customer_name,status,order_kg', 'machine:id,machine,process']);

        if ($machineId > 0) $q->where('machine_id', $machineId);

        $jobs = $q->orderBy('planned_start')->get()->map(function (WwPlanning $p) {
            return [
                'planning_id'    => $p->id,
                'indent_id'      => $p->indent_id,
                'indent_no'      => $p->indent?->indent_no,
                'product'        => $p->indent?->product_name,
                'customer'       => $p->indent?->customer_name,
                'process'        => $p->process,
                'machine_id'     => $p->machine_id,
                'machine'        => $p->machine?->machine,
                'target_kg_hr'   => $p->final_output_kg_hr !== null ? (float) $p->final_output_kg_hr : null,
                'order_kg'       => $p->indent?->order_kg !== null ? (float) $p->indent->order_kg : null,
                'planned_start'  => optional($p->planned_start)->format('Y-m-d H:i'),
                'status'         => $p->status,
            ];
        });

        return response()->json(['ok' => true, 'jobs' => $jobs]);
    }

    /** Record a production entry; derive actuals + OEE; roll up statuses. */
    public function entrySave(Request $request)
    {
        $planningId = (int) $request->query('planning_id', 0);
        $planning = $planningId ? WwPlanning::with('indent')->find($planningId) : null;
        if (! $planning || ! $planning->indent) {
            return response()->json(['ok' => false, 'error' => 'Job not found.'], 404);
        }
        $indent = $planning->indent;

        $start = $request->query('start_time') ?: null;
        $end   = $request->query('end_time') ?: null;
        $stop  = $request->query('stop_reason') ?: null;
        $target = $request->query('target_output_kg_hr');
        $target = ($target !== null && $target !== '') ? (float) $target
                 : ($planning->final_output_kg_hr !== null ? (float) $planning->final_output_kg_hr : null);

        $entry = new WwProductionEntry([
            'indent_id'           => $indent->id,
            'planning_id'         => $planning->id,
            'process'             => $planning->process,
            'machine_id'          => $planning->machine_id,
            'shift'               => $request->query('shift'),
            'start_time'          => $start,
            'end_time'            => $end,
            'produced_qty_pcs'    => (int) $request->query('produced_qty_pcs', 0),
            'produced_kg'         => (float) $request->query('produced_kg', 0),
            'scrap_kg'            => (float) $request->query('scrap_kg', 0),
            'changeover_min'      => (int) $request->query('changeover_min', 0),
            'target_output_kg_hr' => $target,
            'qc_result'           => $request->query('qc_result') ?: null,
            'stop_reason'         => $stop,
            'remarks'             => $request->query('remarks') ?: null,
        ]);
        $entry->recompute(); // engine: actual_hours, actual_output, efficiency_pct
        $entry->status = StatusFlow::entryStatus((bool) $end, $stop);
        $entry->save();

        // roll up planning status
        $entryDone = $entry->status === 'Completed';
        $planning->status = StatusFlow::advance($planning->status, StatusFlow::planningStatus(true, $entryDone));
        $planning->save();

        // roll up indent status across its active processes
        $this->rollUpIndent($indent);

        // OEE for this entry (planned hours = required_hours if known, else actual)
        $plannedHours = $planning->required_hours !== null && (float)$planning->required_hours > 0
            ? (float) $planning->required_hours : (float) $entry->actual_hours;
        $oee = $entry->oee($plannedHours);

        return response()->json([
            'ok'    => true,
            'entry' => [
                'id'                  => $entry->id,
                'status'              => $entry->status,
                'actual_hours'        => (float) $entry->actual_hours,
                'actual_output_kg_hr' => (float) $entry->actual_output_kg_hr,
                'efficiency_pct'      => (float) $entry->efficiency_pct,
            ],
            'oee'         => $oee,
            'indent'      => ['id'=>$indent->id, 'status'=>$indent->fresh()->status],
        ]);
    }

    private function rollUpIndent(WwIndent $indent): void
    {
        $active = $indent->activeProcesses();
        $plannings = $indent->plannings()->get();
        $stepCompleted = [];
        foreach ($active as $proc) {
            $rows = $plannings->where('process', $proc);
            // a process is "done" when it has at least one planning row and all are Completed
            $stepCompleted[] = $rows->isNotEmpty() && $rows->every(fn($r) => $r->status === 'Completed');
        }
        $anyStarted = $indent->productionEntries()->exists();
        $anyPlanned = $plannings->isNotEmpty();

        $proposed = StatusFlow::indentStatus($stepCompleted, $anyStarted, $anyPlanned);
        $indent->status = StatusFlow::advance($indent->status, $proposed);
        $indent->save();
    }
}
