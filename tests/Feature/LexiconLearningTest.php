<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Tenant;
use App\Services\Relationship\LexiconLearner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nightly study (2026-07-18): vocabulary is DATA the bot uses; instructions never change. */
class LexiconLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_learns_vocabulary_caps_and_survives_ai_outage(): void
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('relationship_memory', true);
        for ($i = 0; $i < 8; $i++) {
            Message::withoutGlobalScope('tenant')->create(['tenant_id' => $t->id,
                'customer_phone' => '21190000007'.$i, 'instance' => 'i1', 'direction' => 'in',
                'sender' => 'customer', 'body' => 'give me the blue soap 2 karton kabir']);
        }

        $fake = new class extends LexiconLearner {
            protected function callAi(string $tr, Tenant $t): ?array
            {
                return ['lexicon' => [
                    ['term' => 'blue soap', 'means' => 'Marhaba Beauty Soap 200g', 'evidence' => 'give me the blue soap'],
                    ['term' => 'karton kabir', 'means' => 'large carton (bulk pack)', 'evidence' => '2 karton kabir'],
                ], 'gaps' => ['asked for delivery time, bot gave none'], 'demand' => ['Dettol 500ml']];
            }
        };
        $r = $fake->learn($t);
        $this->assertSame(2, $r['terms']);
        $lex = (array) $t->fresh()->setting('shop_lexicon', []);
        $this->assertCount(2, $lex);
        $this->assertSame('blue soap', $lex[0]['term']);
        $this->assertNotEmpty($lex[0]['evidence'], 'every learning carries its evidence');

        // rerun: dedupe — first learning wins
        $this->assertSame(0, $fake->learn($t)['terms']);

        // AI down → nothing learned, nothing broken
        $dead = new class extends LexiconLearner {
            protected function callAi(string $tr, Tenant $t): ?array { return null; }
        };
        $this->assertSame(0, $dead->learn($t)['terms']);
    }

    public function test_glossary_reaches_the_prompt_as_data_and_the_law_holds(): void
    {
        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('CUSTOMER VOCABULARY (learned', $src);
        $this->assertStringContainsString('shop_lexicon', $src);

        $svc = file_get_contents(app_path('Services/Relationship/LexiconLearner.php'));
        $this->assertStringContainsString('THE LAW HOLDS', $svc);
        $this->assertStringContainsString('report-only', mb_strtolower($svc), 'gaps+demand stay human-facing');

        $rev = file_get_contents(app_path('Console/Commands/RelationshipReviewCommand.php'));
        $this->assertStringContainsString('asked-but-not-stocked', $rev, 'demand becomes a purchasing signal');
    }
}
