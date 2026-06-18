<?php
namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\WwItem;
use App\Models\WwMachine;
use App\Models\WwMaterial;
use App\Services\Winworld\Formula;
use Illuminate\Database\Seeder;

/**
 * Seeds the Win World production masters for the Win World tenant.
 * Idempotent. Resolves the tenant by slug (falls back to a name match);
 * if not found, it explains and exits without touching anything.
 *
 *   php artisan db:seed --class=Database\\Seeders\\WinworldSeeder
 */
class WinworldSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->command?->warn('WinworldSeeder: no Win World tenant found (slug winworld/galaxypack or name like "win world"). Create the tenant first, then re-run.');
            return;
        }
        $tid = $tenant->id;
        $this->command?->info("WinworldSeeder: seeding masters for tenant #{$tid} ({$tenant->name}).");

        // --- 22 machines from the planning workbook ---
        $machines = [
            // [process, machine, max_speed, speed_type]
            ['Extrusion','ABA',144,'Meter/Min'], ['Extrusion','IBC',120,'Meter/Min'], ['Extrusion','S55-1',100,'Meter/Min'],
            ['Extrusion','S45-1',null,'Meter/Min'], ['Extrusion','S45-2',null,'Meter/Min'], ['Extrusion','F45-1',null,'Meter/Min'],
            ['Extrusion','A-1',null,'Meter/Min'], ['Extrusion','A-2',null,'Meter/Min'], ['Extrusion','A-3',null,'Meter/Min'],
            ['Extrusion','A-4',null,'Meter/Min'], ['Extrusion','A-5',null,'Meter/Min'], ['Extrusion','A-6',null,'Meter/Min'],
            ['Extrusion','A-7',null,'Meter/Min'], ['Extrusion','A-8',null,'Meter/Min'], ['Extrusion','A-9',null,'Meter/Min'],
            ['Cutting','SS-1',null,'Pcs/Min'], ['Cutting','SS-3',null,'Pcs/Min'], ['Cutting','BS-3',null,'Pcs/Min'], ['Cutting','BS-4',null,'Pcs/Min'],
            ['Printing','FP-01',null,'Meter/Min'], ['Printing','FP-02',null,'Meter/Min'], ['Printing','FP-03',null,'Meter/Min'],
        ];
        foreach ($machines as [$process,$machine,$speed,$type]) {
            WwMachine::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id'=>$tid, 'machine'=>$machine],
                ['process'=>$process, 'max_speed'=>$speed, 'speed_type'=>$type, 'active'=>true]
            );
        }

        // --- sample items (from Item Master sheet) ---
        $items = [
            ['FGC0230','Clear White Tube Roll 40"x120g','LD PLAIN ROLL',40,120,null],
            ['FGC0231','Clear White Tube Roll 47"x160g','LD PLAIN ROLL',47,160,null],
            ['FGC0232','White Sheet Roll 20"x150g','LD PLAIN ROLL',20,150,null],
        ];
        foreach ($items as [$code,$name,$group,$w,$l,$g]) {
            $gram = ($g !== null) ? round(Formula::gramPerPcs((float)$w,(float)$l,(float)$g),4) : null;
            WwItem::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id'=>$tid, 'item_code'=>$code],
                ['item_name'=>$name,'item_group'=>$group,'width_inch'=>$w,'length_inch'=>$l,'gauge'=>$g,'gram_per_pcs'=>$gram,'status'=>'Active']
            );
        }

        // --- common blending materials (placeholder list to confirm with Win World) ---
        $materials = [
            ['LDPE Resin','resin'], ['LLDPE','resin'], ['HDPE','resin'],
            ['White Masterbatch','masterbatch'], ['CaCO3 Filler','additive'], ['Regrind','additive'],
        ];
        foreach ($materials as $i => [$name,$type]) {
            WwMaterial::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id'=>$tid, 'material_name'=>$name],
                ['material_code'=>'M'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'type'=>$type,'uom'=>'kg','active'=>true]
            );
        }

        // Turn the module on for this tenant (drives Filament navigation visibility).
        if (method_exists($tenant, 'putSetting')) {
            $tenant->putSetting('module_winworld', true);
        }

        $this->command?->info('WinworldSeeder: done — 22 machines, '.count($items).' items, '.count($materials).' materials.');
    }

    private function resolveTenant(): ?Tenant
    {
        foreach (['winworld','galaxypack','win-world','winworld-impex','win_world'] as $slug) {
            $t = Tenant::where('slug',$slug)->first();
            if ($t) return $t;
        }
        return Tenant::where('name','like','%win world%')
            ->orWhere('name','like','%winworld%')
            ->orWhere('name','like','%galaxypack%')->first();
    }
}
