<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\SolarDiscoveryProfile;
use Tests\TestCase;

/**
 * Project Advisor — solar discovery schema + answer→brief mapping (no DB). Proves the questionnaire
 * carries every customer segment, and that raw answers are mapped defensively into the neutral engine
 * brief: loads normalised (blank rows dropped, critical/surge coerced), region → sun hours, and the
 * expansion choice → allowance fraction.
 */
class SolarDiscoveryProfileTest extends TestCase
{
    public function test_schema_shape_and_segments(): void
    {
        $p = new SolarDiscoveryProfile();
        $schema = $p->schema();

        $this->assertSame('solar', $schema['profile']);
        $this->assertCount(5, $schema['sections']);
        $this->assertSame(['customer', 'power', 'loads', 'sizing', 'budget'], array_column($schema['sections'], 'key'));

        $customerType = collect($schema['sections'][0]['fields'])->firstWhere('key', 'customer_type');
        $this->assertContains('Residential', $customerType['options']);
        $this->assertContains('Street Lighting', $customerType['options']);
        $this->assertCount(10, $customerType['options']);
    }

    public function test_to_brief_maps_and_normalises(): void
    {
        $brief = (new SolarDiscoveryProfile())->toBrief([
            'location' => 'Northern',            // → 5.6 sun hours
            'future_expansion' => 'Some (20%)',  // → 0.20
            'backup_hours' => '8',
            'loads' => [
                ['name' => 'Fridge', 'watts' => '150', 'qty' => 1, 'hours_per_day' => 8, 'critical' => '1', 'surge_factor' => '3'],
                ['name' => 'TV', 'watts' => 100, 'qty' => '2', 'hours_per_day' => 4, 'critical' => false],
                ['name' => 'broken', 'watts' => 0, 'hours_per_day' => 5],   // no power → dropped
            ],
        ]);

        $this->assertCount(2, $brief['loads']);
        $this->assertSame(8.0, $brief['backup_hours']);
        $this->assertSame(5.6, $brief['peak_sun_hours']);
        $this->assertSame(0.20, $brief['expansion_pct']);

        $fridge = $brief['loads'][0];
        $this->assertTrue($fridge['critical']);          // '1' → true
        $this->assertSame(3.0, $fridge['surge_factor']); // string → float
        $this->assertSame(2, $brief['loads'][1]['qty']); // '2' → int
    }

    public function test_defaults_when_answers_missing(): void
    {
        $brief = (new SolarDiscoveryProfile())->toBrief([]);
        $this->assertSame([], $brief['loads']);
        $this->assertSame(0.0, $brief['backup_hours']);
        $this->assertSame(5.0, $brief['peak_sun_hours']);   // 'Other' fallback
        $this->assertSame(0.20, $brief['expansion_pct']);   // default 'Some (20%)'
    }
}
