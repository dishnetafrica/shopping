<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\WwMachine;
use App\Models\WwMaintOrder;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Demo maintenance work orders so the Maintenance screen + response KPIs
 * (MTTR / MTBF / PM compliance) show real numbers. Idempotent. Safe to run
 * on an already-seeded tenant without touching other demo data.
 */
class WinworldMaintDemoSeeder extends Seeder
{
    public function run(): void
    {
        $t = Tenant::where('slug', 'winworld')->orWhere('slug', 'galaxypack')->orWhere('slug', 'galaxy')
            ->first()
            ?? Tenant::whereRaw('LOWER(name) LIKE ? OR LOWER(name) LIKE ?', ['%win world%', '%galaxy%'])->first();
        if (! $t) { $this->command->error('WinworldMaintDemoSeeder: no Win World tenant found.'); return; }
        app(TenantContext::class)->set($t->id);
        $tid = $t->id;

        if (WwMaintOrder::where('ref', 'WO-D1')->exists()) {
            $this->command->warn('WinworldMaintDemoSeeder: demo maintenance already present — skipping.');
            return;
        }

        $mid = fn (string $name) => optional(WwMachine::where('machine', $name)->first())->id
            ?? optional(WwMachine::first())->id;

        $rows = [
            // completed breakdowns -> drive MTTR (repair 45m, 90m, 120m)
            ['ref'=>'WO-D1','machine'=>'A-1','type'=>'breakdown','title'=>'Screw drive tripped on overload','priority'=>'High','status'=>'done',
             'reported_at'=>now()->subDays(6)->setTime(9,0),'started_at'=>now()->subDays(6)->setTime(9,15),'completed_at'=>now()->subDays(6)->setTime(10,0),'downtime_min'=>60,'done_by'=>'Maint. Team'],
            ['ref'=>'WO-D2','machine'=>'ABA','type'=>'breakdown','title'=>'Die head heater band failed','priority'=>'Critical','status'=>'done',
             'reported_at'=>now()->subDays(4)->setTime(11,0),'started_at'=>now()->subDays(4)->setTime(11,10),'completed_at'=>now()->subDays(4)->setTime(13,10),'downtime_min'=>140,'done_by'=>'Maint. Team'],
            ['ref'=>'WO-D3','machine'=>'FP-01','type'=>'breakdown','title'=>'Ink pump seal leak','priority'=>'Normal','status'=>'done',
             'reported_at'=>now()->subDays(2)->setTime(14,0),'started_at'=>now()->subDays(2)->setTime(14,30),'completed_at'=>now()->subDays(2)->setTime(15,15),'downtime_min'=>45,'done_by'=>'Maint. Team'],
            // open breakdown (no repair time yet)
            ['ref'=>'WO-D4','machine'=>'SS-1','type'=>'breakdown','title'=>'Cutter blade chatter — needs inspection','priority'=>'High','status'=>'open',
             'reported_at'=>now()->subHours(3),'downtime_min'=>0],
            // preventive: one done on time, one overdue (drags PM compliance), one upcoming
            ['ref'=>'PM-D5','machine'=>'A-1','type'=>'preventive','title'=>'Monthly gearbox grease + belt check','priority'=>'Normal','status'=>'done',
             'due_at'=>now()->subDays(3)->setTime(17,0),'completed_at'=>now()->subDays(4)->setTime(12,0),'done_by'=>'Maint. Team'],
            ['ref'=>'PM-D6','machine'=>'ABA','type'=>'preventive','title'=>'Screen pack change + barrel temp calibration','priority'=>'High','status'=>'open',
             'due_at'=>now()->subDays(1)->setTime(17,0)],
            ['ref'=>'PM-D7','machine'=>'FP-02','type'=>'preventive','title'=>'Anilox roll deep clean','priority'=>'Normal','status'=>'open',
             'due_at'=>now()->addDays(5)->setTime(17,0)],
        ];

        foreach ($rows as $r) {
            $o = new WwMaintOrder();
            $o->fill([
                'machine_id'   => $mid($r['machine']),
                'type'         => $r['type'],
                'title'        => $r['title'],
                'priority'     => $r['priority'],
                'status'       => $r['status'],
                'downtime_min' => $r['downtime_min'] ?? 0,
                'reported_by'  => 'Demo',
                'done_by'      => $r['done_by'] ?? null,
            ]);
            $o->ref          = $r['ref'];
            $o->reported_at  = $r['reported_at']  ?? null;
            $o->started_at   = $r['started_at']   ?? null;
            $o->completed_at = $r['completed_at'] ?? null;
            $o->due_at       = $r['due_at']       ?? null;
            $o->save();
        }

        $this->command->info('WinworldMaintDemoSeeder: done — 4 breakdowns (3 fixed, 1 open) + 3 preventive jobs.');
    }
}
