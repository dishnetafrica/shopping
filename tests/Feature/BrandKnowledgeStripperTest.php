<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** brand_knowledge placeholder stripping: [FILL IN / [TODO lines never reach the prompt. */
class BrandKnowledgeStripperTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_lines_are_removed_from_the_prompt(): void
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('brand_knowledge',
            "WHO WE ARE\nWe are Family Shoppers.\nDELIVERY\n- Kampala: [FILL IN — same-day].\n- Juba: confirmed by team.\nPAYMENT\n- [TODO bank details]");

        $brain = app(AiBrain::class);
        $ref = new \ReflectionMethod($brain, 'systemPrompt');
        $ref->setAccessible(true);
        $prompt = (string) $ref->invoke($brain, $t);

        $this->assertStringNotContainsString('[FILL IN', $prompt);
        $this->assertStringNotContainsString('[TODO', $prompt);
        $this->assertStringContainsString('We are Family Shoppers', $prompt);
        $this->assertStringContainsString('confirmed by team', $prompt);
    }

    public function test_clean_knowledge_passes_through_unchanged(): void
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $clean = "WHO WE ARE\nFamily Shoppers, Juba.\nDELIVERY\n- Kampala same-day.\n- Juba: team confirms.";
        $t->putSetting('brand_knowledge', $clean);

        $brain = app(AiBrain::class);
        $ref = new \ReflectionMethod($brain, 'systemPrompt');
        $ref->setAccessible(true);
        $prompt = (string) $ref->invoke($brain, $t);

        $this->assertStringContainsString('Kampala same-day', $prompt);
        $this->assertStringContainsString('team confirms', $prompt);
    }
}
