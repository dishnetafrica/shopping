<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Sales manners (2026-07-18): the bot is a trusted supplier, not a market tout. */
class SalesMannersTest extends TestCase
{
    public function test_the_prompt_teaches_restraint_not_pushing(): void
    {
        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('SALES DISCIPLINE', $src);
        $this->assertStringContainsString('ANSWER FIRST', $src);
        $this->assertStringContainsString('AT MOST ONCE per conversation', $src);
        $this->assertStringContainsString('after ONE decline', $src, 'a no closes suggesting');
        $this->assertStringNotContainsString('proactively suggest a relevant one', $src,
            'the tout instruction must be gone');
        $this->assertStringNotContainsString('For big buyers push cartons', $src);
    }

    public function test_pipeline_suggestions_only_after_a_committed_add(): void
    {
        $src = file_get_contents(app_path('Jobs/ProcessIncomingMessage.php'));
        $this->assertStringContainsString('if ($addedId && $focal > 0)', $src,
            'a mere product question must not trigger "often bought together"');
    }
}
