<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Services\Relationship\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Progressive profiling (2026-07-18): the bot gets acquainted like a Juba shopkeeper — naturally, once. */
class ProgressiveProfilingTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('relationship_memory', true);
        return $t;
    }

    public function test_brand_new_customer_gets_the_acquaint_instruction(): void
    {
        $b = ProfileContext::block($this->tenant(), '211900000061');
        $this->assertStringContainsString('NEW CUSTOMER', $b);
        $this->assertStringContainsString('FIRST fully answer', $b, 'answering always comes before asking');
        $this->assertStringContainsString('ONE ask only', $b);
    }

    public function test_known_gaps_are_surfaced_once_and_full_profiles_greet_by_name(): void
    {
        $t = $this->tenant();
        CustomerProfile::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'phone' => '211900000062']);
        $b = ProfileContext::block($t, '211900000062');
        $this->assertStringContainsString('STILL UNKNOWN', $b);
        $this->assertStringContainsString('shop + area', $b);

        CustomerProfile::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'phone' => '211900000063',
            'name' => 'Hasan', 'address' => 'Hasan Star Supermarket, Konyo Konyo']);
        $b2 = ProfileContext::block($t, '211900000063');
        $this->assertStringNotContainsString('STILL UNKNOWN', $b2);
        $this->assertStringContainsString('BY NAME', $b2);
    }

    public function test_extractor_fills_empty_identity_columns_only(): void
    {
        $src = file_get_contents(app_path('Services/Relationship/RelationshipExtractor.php'));
        $this->assertStringContainsString('shop_area', $src);
        $this->assertStringContainsString('! trim((string) $profile->name)', $src,
            'never overwrite what a human typed');
    }
}
