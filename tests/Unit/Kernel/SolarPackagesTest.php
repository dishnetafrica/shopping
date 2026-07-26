<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\SolarDiscoveryProfile;
use App\Services\Advisor\SolarPackages;
use App\Services\Advisor\SolarSizingService;
use Tests\TestCase;

/**
 * Project Advisor — solution package integrity (no DB). Proves every predefined package is complete
 * (name, valid segment, marketing copy, equipment, typical loads) and that its typical answers actually
 * size to a real system through the deterministic engine — so "start from a package" always yields a
 * usable, sized proposal.
 */
class SolarPackagesTest extends TestCase
{
    public function test_all_packages_are_complete_and_sizeable(): void
    {
        $profile = new SolarDiscoveryProfile();
        $engine  = new SolarSizingService();
        $segments = SolarDiscoveryProfile::CUSTOMER_TYPES;

        $this->assertGreaterThanOrEqual(8, count(SolarPackages::PACKAGES));

        foreach (SolarPackages::PACKAGES as $p) {
            $label = $p['key'] ?? '(no key)';
            foreach (['key', 'name', 'segment', 'summary', 'answers', 'equipment', 'marketing'] as $f) {
                $this->assertNotEmpty($p[$f] ?? null, "$label missing $f");
            }
            $this->assertContains($p['segment'], $segments, "$label has an invalid segment");
            foreach (['tagline', 'points', 'whatsapp'] as $mk) {
                $this->assertNotEmpty($p['marketing'][$mk] ?? null, "$label missing marketing.$mk");
            }
            $this->assertNotEmpty($p['answers']['loads'] ?? null, "$label has no typical loads");
            $this->assertNotEmpty($p['equipment'], "$label has no equipment");

            // It must size to a real system.
            $r = $engine->size($profile->toBrief($p['answers']))['result'];
            $this->assertGreaterThan(0, $r['pv_array_w'], "$label did not size a PV array");
            $this->assertGreaterThan(0, $r['inverter_va'], "$label did not size an inverter");
        }
    }

    public function test_keys_are_unique(): void
    {
        $keys = array_column(SolarPackages::PACKAGES, 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
    }
}
