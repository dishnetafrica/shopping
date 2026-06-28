<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\CapabilityRegistry;
use App\Services\Knowledge\FactVersioning;
use App\Services\Knowledge\Intent;
use PHPUnit\Framework\TestCase;
use Tests\Support\Knowledge\TestCapability;
use Tests\Support\Knowledge\TestExtractor;

/**
 * Tests that protect the ARCHITECTURE itself, not business behaviour. These are the regressions
 * that slip past code review but quietly destroy genericness. Pure / filesystem only — no DB.
 */
class ArchitectureTest extends TestCase
{
    /** Registering a new capability requires ZERO engine changes — just a registry call. */
    public function test_new_capability_registers_without_engine_changes(): void
    {
        $reg = new CapabilityRegistry();
        $reg->register(new TestCapability());           // a capability the engine has never heard of
        $this->assertSame('test_capability', $reg->forIntent(TestCapability::INTENT_GREETING)?->name());
    }

    /** A capability may use an intent string that is NOT in the engine's core Intent constants. */
    public function test_capability_can_use_intent_outside_core_enum(): void
    {
        $defined = (new \ReflectionClass(Intent::class))->getConstants();
        $this->assertNotContains('greeting', $defined, 'guard: "greeting" must stay a non-core intent for this test to mean anything');

        $reg = new CapabilityRegistry();
        $reg->register(new TestCapability());
        $this->assertNotNull($reg->forIntent('greeting'));   // routes anyway → engine is open, not enum-bound
    }

    /** Two capabilities can claim the same intent without modifying the engine (first wins). */
    public function test_two_capabilities_same_intent(): void
    {
        $reg = new CapabilityRegistry();
        $reg->register(new TestCapability());
        $second = new class extends TestCapability { public function name(): string { return 'second'; } };
        $reg->register($second);
        $this->assertSame('test_capability', $reg->forIntent('greeting')?->name());
        $this->assertCount(2, $reg->all());
    }

    /** The engine namespace must not reference any application/domain class. */
    public function test_engine_has_no_domain_references(): void
    {
        $dir = dirname(__DIR__, 3) . '/app/Services/Knowledge';
        // Guard real coupling — references to application/domain CLASSES — not English prose.
        $forbidden = ['App\\Apps', 'use App\\Apps', 'DailyMenu', 'MerchantAssistant', 'BotBrain',
                      'MenuProjection', 'TodayMenu', 'App\\Models\\DailyMenu'];
        $offenders = [];
        foreach ($this->phpFiles($dir) as $file) {
            $src = file_get_contents($file);
            foreach ($forbidden as $needle) {
                if (stripos($src, $needle) !== false) $offenders[] = basename($file) . " :: {$needle}";
            }
        }
        $this->assertSame([], $offenders, "Engine leaked domain references: " . implode(', ', $offenders));
    }

    /** Facts are append-only: versions increment, unchanged values write nothing. */
    public function test_facts_are_append_only_by_rule(): void
    {
        $this->assertSame(1, FactVersioning::nextVersion(null));
        $this->assertSame(2, FactVersioning::nextVersion(1));
        $this->assertTrue(FactVersioning::changed(['price' => 4000], ['price' => 5000]));
        $this->assertFalse(FactVersioning::changed(['price' => 5000], ['price' => 5000]));
    }

    /** One message yields multiple facts AND multiple actions. */
    public function test_one_message_yields_multiple_facts_and_actions(): void
    {
        $r = (new TestExtractor())->extract('Hello there');
        $this->assertGreaterThanOrEqual(2, count($r->facts));
        $this->assertGreaterThanOrEqual(2, count($r->actions));
    }

    /** Append-only is enforced by routing ALL fact writes through BusinessMemory. Guard it. */
    public function test_only_business_memory_writes_facts(): void
    {
        $dir = dirname(__DIR__, 3) . '/app';
        $writePatterns = ['KnowledgeFact::create', 'new KnowledgeFact'];
        $offenders = [];
        foreach ($this->phpFiles($dir) as $file) {
            if (basename($file) === 'BusinessMemory.php' || basename($file) === 'KnowledgeFact.php') continue;
            $src = file_get_contents($file);
            foreach ($writePatterns as $p) {
                if (str_contains($src, $p)) $offenders[] = basename($file) . " :: {$p}";
            }
        }
        $this->assertSame([], $offenders, "Facts must only be written via BusinessMemory: " . implode(', ', $offenders));
    }


    /** New capabilities must NEVER write to DailyState directly — only via OperationalStateStore. */
    public function test_apps_do_not_touch_daily_state(): void
    {
        $dir = dirname(__DIR__, 3) . '/app/Apps';
        if (! is_dir($dir)) { $this->markTestSkipped('no app/Apps yet'); }
        $offenders = [];
        foreach ($this->phpFiles($dir) as $file) {
            if (str_contains(file_get_contents($file), 'DailyState')) $offenders[] = basename($file);
        }
        $this->assertSame([], $offenders, 'Apps must use OperationalStateStore, not DailyState: ' . implode(', ', $offenders));
    }

    /** @return string[] */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname(); }
        return $out;
    }
}
