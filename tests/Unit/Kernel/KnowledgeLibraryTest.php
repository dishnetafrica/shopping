<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\KnowledgeLibrary;
use App\Services\Advisor\SolarKnowledge;
use Tests\TestCase;

/**
 * Project Advisor — knowledge library content + filtering (no DB). Proves every entry is complete with a
 * valid type (and objections/messages carry a WhatsApp-ready reply), and that search filters correctly by
 * type, free-text query, and the two combined.
 */
class KnowledgeLibraryTest extends TestCase
{
    public function test_entries_are_complete(): void
    {
        $all = (new KnowledgeLibrary())->all('solar');
        $this->assertGreaterThanOrEqual(20, count($all));

        foreach ($all as $e) {
            foreach (['key', 'type', 'category', 'title', 'body'] as $k) {
                $this->assertNotEmpty($e[$k] ?? null, ($e['key'] ?? '?') . " missing $k");
            }
            $this->assertContains($e['type'], SolarKnowledge::TYPES);
            if (in_array($e['type'], ['objection', 'message'], true)) {
                $this->assertNotEmpty($e['whatsapp'] ?? null, $e['key'] . ' needs a WhatsApp reply');
            }
        }
        $keys = array_column($all, 'key');
        $this->assertSame(count($keys), count(array_unique($keys)), 'keys must be unique');
    }

    public function test_filter_by_type(): void
    {
        $lib = new KnowledgeLibrary();
        $faq = $lib->search('solar', ['type' => 'faq']);
        $this->assertNotEmpty($faq);
        $this->assertEmpty(array_filter($faq, fn ($e) => $e['type'] !== 'faq'));

        $msgs = $lib->search('solar', ['type' => 'message']);
        $this->assertEmpty(array_filter($msgs, fn ($e) => empty($e['whatsapp'])));
    }

    public function test_free_text_search_and_combined(): void
    {
        $lib = new KnowledgeLibrary();

        $gen = $lib->search('solar', ['q' => 'generator']);
        $this->assertTrue(collect($gen)->contains(fn ($e) => $e['key'] === 'obj_generator'));

        $this->assertEmpty($lib->search('solar', ['q' => 'zznotfoundzz']));

        $combined = $lib->search('solar', ['type' => 'faq', 'q' => 'battery']);
        $this->assertNotEmpty($combined);
        $this->assertEmpty(array_filter($combined, fn ($e) => $e['type'] !== 'faq'));
    }

    public function test_unknown_profile_is_empty(): void
    {
        $this->assertSame([], (new KnowledgeLibrary())->all('hvac'));
    }
}
