<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\SolarAppliances;
use Tests\TestCase;

/**
 * Solar Experience Center — appliance library (no DB). Proves the presets are complete and that a
 * customer selection maps to engine loads correctly (blank/zero rows dropped, watts/hours/critical/surge
 * carried through) — the bridge that lets customers pick devices instead of kWh.
 */
class SolarAppliancesTest extends TestCase
{
    public function test_presets_are_complete(): void
    {
        $all = SolarAppliances::all();
        $this->assertGreaterThanOrEqual(12, count($all));
        foreach ($all as $a) {
            foreach (['key', 'name', 'icon', 'watts', 'hours', 'category'] as $k) {
                $this->assertArrayHasKey($k, $a);
            }
            $this->assertGreaterThan(0, $a['watts']);
        }
    }

    public function test_to_loads_maps_and_filters(): void
    {
        $loads = SolarAppliances::toLoads([
            ['key' => 'lights', 'qty' => 6],
            ['key' => 'ac', 'qty' => 1],
            ['key' => 'bogus', 'qty' => 2],   // unknown → dropped
            ['key' => 'fan', 'qty' => 0],     // zero → dropped
        ]);

        $this->assertCount(2, $loads);
        $lights = $loads[0];
        $this->assertSame('LED lights (per bulb)', $lights['name']);
        $this->assertSame(6, $lights['qty']);
        $this->assertSame(10, $lights['watts']);
        $this->assertTrue($lights['critical']);

        $ac = $loads[1];
        $this->assertSame(1200, $ac['watts']);
        $this->assertSame(3, $ac['surge_factor']);   // AC start-up surge
        $this->assertFalse($ac['critical']);
    }
}
