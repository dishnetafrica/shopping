<?php

namespace Tests\Feature\Bos;

use PHPUnit\Framework\TestCase;

/**
 * Architecture fitness tests (BOS v1.0). These fail the build when the code drifts from the
 * constitution — they are enforcement, not review suggestions. Pure static scans: no DB, no boot.
 *
 * Scope grows per slice. Slice 1 asserts the foundation invariants that must hold forever:
 *   - Events are append-only.
 *   - Projections never persist to business objects.
 *   - Business rules live only in the rule layer, and every rule is registered + has a named test.
 */
class ArchitectureFitnessTest extends TestCase
{
    private function base(): string { return dirname(__DIR__, 3); }

    private function phpFiles(string $dir): array
    {
        $root = $this->base() . '/' . ltrim($dir, '/');
        if (! is_dir($root)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[$f->getPathname()] = file_get_contents($f->getPathname());
        }
        return $out;
    }

    public function test_events_are_append_only(): void
    {
        $src = file_get_contents($this->base() . '/app/BOS/Event.php');
        $this->assertMatchesRegularExpression('/static::updating\(/', $src, 'Event must guard updates');
        $this->assertMatchesRegularExpression('/static::deleting\(/', $src, 'Event must guard deletes');
        $this->assertStringContainsString('append-only', $src);
    }

    public function test_projections_never_persist(): void
    {
        $forbidden = '/->save\(|->update\(|->delete\(|->insert\(|::create\(|::insert\(|DB::/';
        foreach ($this->phpFiles('app/BOS/Projections') as $path => $src) {
            $this->assertDoesNotMatchRegularExpression(
                $forbidden, $src,
                'Projection must be read-only (no persistence): ' . basename($path)
            );
        }
    }

    public function test_business_rules_live_only_in_rule_layer(): void
    {
        // Any class implementing BusinessRule must sit under app/BOS/Rules/Rules.
        foreach ($this->phpFiles('app/BOS') as $path => $src) {
            if (preg_match('/implements\s+BusinessRule\b/', $src)) {
                $this->assertStringContainsString(
                    '/app/BOS/Rules/Rules/', str_replace('\\', '/', $path),
                    'Business rules may only be defined in the rule layer: ' . $path
                );
            }
        }
    }

    public function test_every_rule_is_registered_and_tested(): void
    {
        // Collect rule codes from the concrete rule classes.
        $codes = [];
        foreach ($this->phpFiles('app/BOS/Rules/Rules') as $src) {
            if (preg_match("/function code\(\)[^\\n]*return '([^']+)'/", $src, $m)) $codes[] = $m[1];
        }
        $this->assertNotEmpty($codes, 'No business rules found');

        $registry = file_get_contents($this->base() . '/app/BOS/Rules/RuleRegistry.php');
        $tests    = file_get_contents($this->base() . '/tests/Unit/Bos/BusinessRuleTest.php');

        foreach ($codes as $code) {
            // Registered in the single registry…
            $this->assertMatchesRegularExpression(
                '/register\(new Rules\\\\/', $registry, 'Rules must be registered in RuleRegistry'
            );
            // …and covered by a named test (test_R#_…).
            $this->assertMatchesRegularExpression(
                '/function test_' . preg_quote($code, '/') . '_/i', $tests,
                "Rule {$code} must have a named test (test_{$code}_…)"
            );
        }
    }

    public function test_event_store_is_the_only_emitter(): void
    {
        // Outside the BOS package, code should emit via Bos::emit — never new Event(...)->save().
        foreach ($this->phpFiles('app') as $path => $src) {
            if (str_contains(str_replace('\\', '/', $path), '/app/BOS/')) continue;
            $this->assertStringNotContainsString(
                'new \App\BOS\Event(', $src,
                'Emit events via Bos::emit(), never by constructing Event directly: ' . basename($path)
            );
        }
    }

    /** Change 3 — keep projections generic: only the four canonical TYPES may extend Projection. */
    public function test_projection_types_capped_at_four(): void
    {
        $canonical = ['TimelineProjection', 'ActivityProjection', 'TodayProjection', 'MetricsProjection'];
        foreach ($this->phpFiles('app/BOS/Projections') as $path => $src) {
            $class = basename($path, '.php');
            if ($class === 'Projection') continue; // the abstract root
            if (preg_match('/class\s+\w+\s+extends\s+Projection\b/', $src)) {
                $this->assertContains(
                    $class, $canonical,
                    "Only the 4 canonical projection types may extend Projection directly; a 5th ({$class}) needs an ADR (ADR-014). Concrete projections extend a canonical base."
                );
            }
        }
    }

    /** Change 2 — business logic (money thresholds) must go through the rule engine, not inline in BOS. */
    public function test_bos_logic_routes_through_rule_engine(): void
    {
        foreach ($this->phpFiles('app/BOS') as $path => $src) {
            $p = str_replace('\\', '/', $path);
            if (str_contains($p, '/app/BOS/Rules/')) continue; // the rule layer is where predicates live
            // Heuristic: a raw comparison against ->balance is a rule decision that belongs in RuleRegistry.
            $this->assertDoesNotMatchRegularExpression(
                '/[<>]=?\s*\$?\w+(->|\[)[\'"]?balance/',
                $src,
                'Inline balance comparison in ' . basename($path) . ' — enforce via Bos::enforce(\'R3\', …) instead (ADR-013).'
            );
        }
    }
}
