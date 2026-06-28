<?php
namespace Tests\Unit\Knowledge;

use App\Models\KnowledgeAction;
use App\Services\Knowledge\CapabilityRegistry;
use App\Services\Knowledge\Contracts\Capability;
use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\Dto\ExtractionResult;
use App\Services\Knowledge\ProjectionCoordinator;
use PHPUnit\Framework\TestCase;

class ProjectionCoordinatorTest extends TestCase
{
    public function test_partial_failure_is_isolated_and_idempotency_skips_applied(): void
    {
        $reg = new CapabilityRegistry();
        $reg->register($this->cap('ok'));
        $reg->register($this->cap('boom'));

        $coord = new ProjectionCoordinator($reg);
        $a1 = new KnowledgeAction(['capability' => 'ok', 'action_type' => 'x', 'status' => 'pending']);
        $a2 = new KnowledgeAction(['capability' => 'boom', 'action_type' => 'y', 'status' => 'pending']);   // throws
        $a3 = new KnowledgeAction(['capability' => 'ok', 'action_type' => 'z', 'status' => 'applied']);     // skip

        $report = $coord->project([$a1, $a2, $a3]);

        $this->assertCount(1, $report->applied);     // a1 only
        $this->assertCount(1, $report->failed);      // a2 isolated, did not abort a3
        $this->assertCount(1, $report->skipped);     // a3 idempotent skip
        $this->assertFalse($report->allOk());
    }

    public function test_unknown_capability_fails_without_throwing(): void
    {
        $coord = new ProjectionCoordinator(new CapabilityRegistry());
        $report = $coord->project([new KnowledgeAction(['capability' => 'ghost', 'action_type' => 'x', 'status' => 'pending'])]);
        $this->assertCount(1, $report->failed);
        $this->assertSame('no_capability', $report->failed[0]['error']);
    }

    private function cap(string $name): Capability
    {
        return new class($name) implements Capability {
            public function __construct(private string $n) {}
            public function name(): string { return $this->n; }
            public function intents(): array { return []; }
            public function extractor(): Extractor {
                return new class implements Extractor { public function extract(string $t, array $p = []): ExtractionResult { return new ExtractionResult(); } };
            }
            public function projector(): Projector {
                $name = $this->n;
                return new class($name) implements Projector {
                    public function __construct(private string $n) {}
                    public function apply(KnowledgeAction $a): string { if ($this->n === 'boom') throw new \RuntimeException('boom'); return 'ok'; }
                    public function revert(KnowledgeAction $a): void {}
                };
            }
        };
    }
}
