<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Chat\ChatTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Arabic SEARCH keywords only (Tier 2) — the Arabic UI (Tier 1) was rolled back by owner decision 2026-07-18. */
class ArabicStorefrontTest extends TestCase
{
    use RefreshDatabase;

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
