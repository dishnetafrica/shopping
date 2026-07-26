<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\SolarSizingService;
use Tests\TestCase;

/**
 * Project Advisor — deterministic solar sizing (no DB). Proves the engine turns a discovery brief into
 * correct, repeatable engineering numbers: connected/critical load, daily energy, peak demand, PV array,
 * battery bank, and inverter rating — with safety margin and expansion applied. Lives under …/Kernel so
 * it is covered by the green gate. This is the numeric foundation the AI is only allowed to explain.
 */
class SolarSizingTest extends TestCase
{
    private function brief(): array
    {
        return [
            'loads' => [
                ['name' => 'LED light',     'watts' => 10,  'qty' => 4, 'hours_per_day' => 5, 'critical' => true],
                ['name' => 'TV',            'watts' => 100, 'qty' => 1, 'hours_per_day' => 4, 'critical' => true],
                ['name' => 'Fridge',        'watts' => 150, 'qty' => 1, 'hours_per_day' => 8, 'critical' => true],
                ['name' => 'Phone charger', 'watts' => 10,  'qty' => 1, 'hours_per_day' => 3, 'critical' => false],
            ],
            'backup_hours' => 8,
        ];
    }

    public function test_full_worked_example(): void
    {
        $r = (new SolarSizingService())->size($this->brief())['result'];

        $this->assertEqualsWithDelta(300.0, $r['connected_load_w'], 0.01);
        $this->assertEqualsWithDelta(290.0, $r['critical_load_w'], 0.01);
        $this->assertEqualsWithDelta(1830.0, $r['daily_energy_wh'], 0.01);
        $this->assertEqualsWithDelta(1.83, $r['daily_energy_kwh'], 0.001);
        $this->assertEqualsWithDelta(300.0, $r['peak_demand_w'], 0.01);
        $this->assertEqualsWithDelta(585.6, $r['pv_array_w'], 0.05);       // 1830/0.75/5 × 1.2
        $this->assertSame(2, $r['panel_count']);                           // ceil(585.6 / 550)
        $this->assertEqualsWithDelta(3866.67, $r['battery_bank_wh'], 0.05); // 290×8/(0.8×0.9) × 1.2
        $this->assertEqualsWithDelta(161.11, $r['battery_bank_ah'], 0.05);  // 3866.67 / 24
        $this->assertEqualsWithDelta(375.0, $r['inverter_w'], 0.01);        // 300 × 1.25
        $this->assertEqualsWithDelta(468.75, $r['inverter_va'], 0.01);      // 375 / 0.8
    }

    public function test_is_deterministic(): void
    {
        $svc = new SolarSizingService();
        $this->assertSame($svc->size($this->brief()), $svc->size($this->brief()));
    }

    public function test_empty_brief_is_safe_and_zeroed(): void
    {
        $r = (new SolarSizingService())->size([])['result'];
        $this->assertSame(0.0, $r['pv_array_w']);
        $this->assertSame(0.0, $r['battery_bank_wh']);
        $this->assertSame(0.0, $r['inverter_va']);
    }

    public function test_surge_raises_peak_but_not_connected_load(): void
    {
        $r = (new SolarSizingService())->size([
            'loads' => [['watts' => 1000, 'qty' => 1, 'hours_per_day' => 2, 'surge_factor' => 3, 'critical' => true]],
            'backup_hours' => 2,
        ])['result'];
        $this->assertEqualsWithDelta(1000.0, $r['connected_load_w'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $r['peak_demand_w'], 0.01);   // surge ×3
    }

    public function test_config_overrides_change_result(): void
    {
        $svc = new SolarSizingService();
        $base = $svc->size($this->brief())['result'];
        $v48  = $svc->size($this->brief(), ['system_voltage' => 48])['result'];
        // Same energy, double the voltage -> half the amp-hours.
        $this->assertEqualsWithDelta($base['battery_bank_ah'] / 2, $v48['battery_bank_ah'], 0.05);

        // A bigger safety margin raises the inverter rating.
        $bigger = $svc->size($this->brief(), ['safety_margin' => 1.5])['result'];
        $this->assertGreaterThan($base['inverter_w'], $bigger['inverter_w']);
    }

    public function test_result_carries_explainable_steps_and_assumptions(): void
    {
        $out = (new SolarSizingService())->size($this->brief());
        $this->assertSame('solar', $out['engine']);
        $this->assertCount(9, $out['steps']);
        $this->assertArrayHasKey('safety_margin', $out['assumptions']);
        foreach ($out['steps'] as $s) {
            $this->assertArrayHasKey('formula', $s);
            $this->assertArrayHasKey('value', $s);
        }
    }
}
