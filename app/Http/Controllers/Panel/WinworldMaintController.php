<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwMachine;
use App\Models\WwMaintOrder;
use App\Models\WwProductionEntry;
use App\Services\Winworld\Maintenance;
use Illuminate\Http\Request;

/**
 * CMMS-lite: breakdown log + preventive work orders, and the response KPIs
 * (MTTR / MTBF / PM compliance) that measure how fast the floor fixes what OEE flags.
 */
class WinworldMaintController extends Controller
{
    public function maintPage(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/maintenance.html');
        if (! is_file($path)) abort(500, 'Maintenance asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function data(Request $r)
    {
        $days  = max(1, (int) $r->query('days', 30));
        $since = now()->subDays($days);

        $orders = WwMaintOrder::orderByDesc('id')->limit(300)->get()
            ->map(fn(WwMaintOrder $o) => [
                'id'           => $o->id,
                'ref'          => $o->ref,
                'machine_id'   => $o->machine_id,
                'machine'      => $o->machine->machine ?? ('#' . $o->machine_id),
                'type'         => $o->type,
                'title'        => $o->title,
                'priority'     => $o->priority,
                'status'       => $o->status,
                'reported_at'  => optional($o->reported_at)->format('Y-m-d H:i'),
                'started_at'   => optional($o->started_at)->format('Y-m-d H:i'),
                'completed_at' => optional($o->completed_at)->format('Y-m-d H:i'),
                'due_at'       => optional($o->due_at)->format('Y-m-d H:i'),
                'downtime_min' => (int) $o->downtime_min,
                'notes'        => $o->notes,
            ])->all();

        // operating hours in the window = sum of production run hours (for MTBF)
        $opHours = (float) WwProductionEntry::where('start_time', '>=', $since)->sum('actual_hours');

        return response()->json([
            'ok'       => true,
            'days'     => $days,
            'orders'   => $orders,
            'summary'  => Maintenance::summary($orders, $opHours, now()->toDateTimeString()),
            'by_machine' => Maintenance::byMachine($orders),
            'machines' => WwMachine::orderBy('machine')->get(['id','machine','process']),
        ]);
    }

    /** Create a breakdown report or a preventive job. */
    public function save(Request $r)
    {
        $type = (string) $r->input('type', 'breakdown');
        if (! in_array($type, ['breakdown', 'preventive'], true)) {
            return response()->json(['ok' => false, 'error' => 'Bad type'], 422);
        }
        $o = new WwMaintOrder();
        $o->fill([
            'machine_id'   => $r->input('machine_id') ?: null,
            'type'         => $type,
            'title'        => (string) $r->input('title', ''),
            'priority'     => (string) $r->input('priority', 'Normal'),
            'status'       => 'open',
            'downtime_min' => (int) $r->input('downtime_min', 0),
            'notes'        => (string) $r->input('notes', ''),
            'reported_by'  => (string) ($r->user()->name ?? 'Staff'),
        ]);
        $prefix = $type === 'preventive' ? 'PM' : 'WO';
        $o->ref = $prefix . str_pad((string) (WwMaintOrder::count() + 1), 4, '0', STR_PAD_LEFT);
        if ($type === 'breakdown') $o->reported_at = now();
        if ($type === 'preventive' && $r->filled('due_at')) $o->due_at = $r->input('due_at');
        $o->save();
        return response()->json(['ok' => true, 'id' => $o->id, 'ref' => $o->ref]);
    }

    /** Transition a work order: start | complete | reopen. */
    public function action(Request $r)
    {
        $o = WwMaintOrder::find((int) $r->input('id'));
        if (! $o) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        $a = (string) $r->input('action');

        if ($a === 'start') {
            $o->started_at = $o->started_at ?: now();
            $o->status = 'in_progress';
        } elseif ($a === 'complete') {
            $o->started_at = $o->started_at ?: ($o->reported_at ?: now());
            $o->completed_at = now();
            $o->status = 'done';
            $o->done_by = (string) ($r->user()->name ?? 'Staff');
            if ($r->filled('downtime_min')) $o->downtime_min = (int) $r->input('downtime_min');
            if ($r->filled('note')) $o->notes = trim(($o->notes ? $o->notes . ' · ' : '') . $r->input('note'));
        } elseif ($a === 'reopen') {
            $o->status = 'open';
            $o->completed_at = null;
        } else {
            return response()->json(['ok' => false, 'error' => 'Bad action'], 422);
        }
        $o->save();
        return response()->json(['ok' => true, 'status' => $o->status]);
    }
}
