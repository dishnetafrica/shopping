<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Chat\ChatTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Storefront Arabic support: Tier 1 (UI mode) source-pinned · Tier 2 (search keywords) behavioral. */
class ArabicStorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_ships_the_arabic_ui_mode(): void
    {
        $html = file_get_contents(resource_path('storefront/shop.html'));
        $this->assertStringContainsString('I18N_AR', $html, 'the dictionary must exist');
        $this->assertStringContainsString("setAttribute('dir','rtl')", $html, 'Arabic must flip the layout RTL');
        $this->assertStringContainsString('id="langbtn"', $html, 'the toggle must be in the search bar');
        $this->assertStringContainsString('default ENGLISH for every new visitor', $html,
            'no browser auto-detect — Arabic is opt-in via the toggle only');
        $this->assertStringContainsString("T('Add to cart')", $html);
        $this->assertStringContainsString("T('Place order')", $html);
        $this->assertStringContainsString("'Place order':'إتمام الطلب'", $html, 'dictionary keys must be plain strings');
    }

    public function test_arabic_keywords_command_enriches_and_is_idempotent(): void
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $oil = Product::create(['tenant_id' => $t->id, 'name' => 'Sunflower Oil 5L', 'price' => 10, 'active' => true, 'keywords' => 'oil, cooking']);
        $done = Product::create(['tenant_id' => $t->id, 'name' => 'Sugar 50kg', 'price' => 40, 'active' => true, 'keywords' => 'سكر']);

        $fake = new class extends ChatTranslator {
            public int $calls = 0;
            public function enabled(): bool { return true; }
            public function arabicKeywords(string $n, string $c = ''): ?string { $this->calls++; return 'زيت, زيت طعام'; }
        };
        $this->app->instance(ChatTranslator::class, $fake);

        $this->artisan('products:arabic-keywords', ['tenant' => $t->id])->assertExitCode(0);

        $this->assertSame('oil, cooking, زيت, زيت طعام', $oil->fresh()->keywords, 'Arabic appended, English kept');
        $this->assertSame('سكر', $done->fresh()->keywords, 'already-Arabic products skipped');
        $this->assertSame(1, $fake->calls, 'one call per candidate — idempotent by detection');
    }
}
