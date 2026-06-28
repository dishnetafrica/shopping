<?php
namespace Tests\Feature\Knowledge;

use App\Models\DailyMenu;
use App\Models\KnowledgeAction;
use App\Models\Tenant;
use App\Apps\DailyMenu\MenuParity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectionParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_match_and_mismatch(): void
    {
        $t = Tenant::factory()->create();
        app(TenantContext::class)->set($t->id);
        $date = date('Y-m-d');

        KnowledgeAction::create([
            'tenant_id' => $t->id, 'capability' => 'daily_menu', 'action_type' => 'add_menu_item',
            'target' => 'Thali', 'params_json' => ['meal' => 'lunch', 'price' => 25000, 'date' => $date],
            'status' => 'applied',
        ]);

        // live projection that matches the rebuilt expectation
        DailyMenu::create([
            'tenant_id' => $t->id, 'menu_date' => $date,
            'payload_json' => ['meals' => ['lunch' => [['name' => 'Thali', 'price' => 25000]]], 'specials' => []],
        ]);

        $parity = app(MenuParity::class);
        $this->assertTrue($parity->checkDailyMenu($t->id, $date)['match']);

        // now drift the live projection
        DailyMenu::where('tenant_id', $t->id)->where('menu_date', $date)
            ->update(['payload_json' => ['meals' => ['lunch' => []], 'specials' => []]]);
        $this->assertFalse($parity->checkDailyMenu($t->id, $date)['match']);
    }
}
