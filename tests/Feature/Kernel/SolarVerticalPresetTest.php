<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Filament\Admin\Resources\TenantResource;
use App\Models\SpecDefinition;
use App\Models\Tenant;
use App\Support\TenantContext;
use App\Support\Vertical;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Onboarding a Solar tenant: "Solar / equipment" is a real business type, and selecting it seeds the
 * starter product-spec catalogue (idempotently). Other business types are not seeded.
 */
class SolarVerticalPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_solar_is_a_valid_business_type(): void
    {
        $this->assertTrue(Vertical::isValid('solar'));
        $this->assertSame('Solar / equipment', Vertical::LABELS['solar']);
    }

    public function test_selecting_solar_seeds_the_spec_catalogue(): void
    {
        $t = Tenant::create(['name' => 'GSL', 'slug' => 'gsl-' . substr(md5(uniqid('', true)), 0, 8)]);
        $t->putSetting('vertical', 'solar');
        app(TenantContext::class)->set($t->id);

        TenantResource::applyVerticalPreset($t);
        $this->assertSame(10, SpecDefinition::where('tenant_id', $t->id)->count());

        // idempotent — saving the solar tenant again does not duplicate
        TenantResource::applyVerticalPreset($t);
        $this->assertSame(10, SpecDefinition::where('tenant_id', $t->id)->count());
    }

    public function test_non_solar_tenant_is_not_seeded(): void
    {
        $t = Tenant::create(['name' => 'Groc', 'slug' => 'g-' . substr(md5(uniqid('', true)), 0, 8)]);
        $t->putSetting('vertical', 'grocery');
        app(TenantContext::class)->set($t->id);

        TenantResource::applyVerticalPreset($t);
        $this->assertSame(0, SpecDefinition::where('tenant_id', $t->id)->count());
    }
}
