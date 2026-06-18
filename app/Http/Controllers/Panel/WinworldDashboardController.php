<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwIndent;
use App\Models\WwMachine;
use App\Models\WwPlanning;
use App\Models\WwProductionEntry;
use App\Services\Winworld\Analytics;
use App\Services\Winworld\MaterialYield;
use Illuminate\Http\Request;

/** Win World OEE dashboard: turns captured data into the efficiency picture. */
class WinworldDashboardController extends Controller
{
    public function dashboardPage(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/dashboard.html');
        if (! is_file($path)) abort(500, 'Dashboard asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function scoreboardPage(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/scoreboard.html');
        if (! is_file($path)) abort(500, 'Scoreboard asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function data(Request $r)
    {
        $days = max(0, (int) $r->query('days', 30));
        $since = $days > 0 ? now()->subDays($days) : null;

        $entryQ = WwProductionEntry::query();
        if ($since) $entryQ->where(function ($q) use ($since) {
            $q->whereNull('start_time')->orWhere('start_time', '>=', $since);
        });
        $entries = $entryQ->get([
            'machine_id','stop_reason','actual_hours','produced_kg','scrap_kg','target_output_kg_hr','efficiency_pct','status','start_time','changeover_min','input_kg','regrind_kg',
        ])->map(fn($e) => $e->toArray())->all();

        $indents   = WwIndent::get(['id','status','order_kg'])->map(fn($x) => $x->toArray())->all();
        $plannings = WwPlanning::get(['machine_id','planned_start','planned_end','required_hours'])->map(fn($p) => $p->toArray())->all();
        $machines  = WwMachine::get(['id','machine','process'])->keyBy('id');

        // per-machine planned hours (scheduled load)
        $plannedByMachine = [];
        foreach ($plannings as $p) {
            $mid = $p['machine_id'] ?? null;
            if ($mid) $plannedByMachine[$mid] = ($plannedByMachine[$mid] ?? 0) + (float) ($p['required_hours'] ?? 0);
        }

        // group entries by machine
        $byMachine = [];
        foreach ($entries as $e) {
            $mid = $e['machine_id'] ?? null;
            if ($mid) $byMachine[$mid][] = $e;
        }

        $perMachine = [];
        foreach ($byMachine as $mid => $es) {
            $oee = Analytics::machineOee($es, (float) ($plannedByMachine[$mid] ?? 0));
            $m = $machines->get($mid);
            $perMachine[] = $oee + [
                'machine_id' => $mid,
                'machine'    => $m->machine ?? ('#' . $mid),
                'process'    => $m->process ?? '',
            ];
        }
        usort($perMachine, fn($a, $b) => $a['oee'] <=> $b['oee']); // worst first = act here

        return response()->json([
            'ok'           => true,
            'days'         => $days,
            'summary'      => Analytics::summary($indents, $entries),
            'yield'        => MaterialYield::rollup($entries),
            'per_machine'  => $perMachine,
            'downtime'     => Analytics::downtimePareto($entries),
            'machine_board'=> Analytics::machineBoard($plannings, now()),
            'machines'     => $machines->map(fn($m) => ['id'=>$m->id,'machine'=>$m->machine,'process'=>$m->process])->values(),
            'weekly_capacity_hrs' => 84,
        ]);
    }
}
