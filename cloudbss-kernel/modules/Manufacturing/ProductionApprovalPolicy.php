<?php declare(strict_types=1);
namespace CloudBSS\Modules\Manufacturing;

use CloudBSS\Kernel\Decision\{Decision, DecisionPolicy};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** Auto-release production under a cost threshold; else require a human. Pure kernel API. */
final class ProductionApprovalPolicy implements DecisionPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}
    public function name(): string { return 'ProductionApproval'; }
    public function version(): int { return 1; }
    public function evaluate(array $c): Decision
    {
        /** @var Money $est */ $est = $c['estCost']; /** @var Money $limit */ $limit = $c['limit'];
        $auto = !$limit->lessThan($est);
        return new Decision($c['tenantId'], $c['entityId'], 'ProductionApproval', 'policy', $this->name(),
            $this->name(), $this->version(), ['limitMinor'=>$limit->minor,'currency'=>$limit->currency->code],
            ['estMinor'=>$est->minor,'currency'=>$est->currency->code], $c['evidenceRefs'] ?? [],
            $auto ? 'auto-released' : 'requires human approval',
            $auto ? "Est {$est->format()} within {$limit->format()}" : "{$est->format()} exceeds {$limit->format()}",
            $auto ? 90 : 100, !$auto, $c['linkedRef'] ?? null, $this->clock, $this->ids);
    }
}
