<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Decision\{Decision, DecisionPolicy};
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** A tenant whose rule is "every purchase needs a human". Different approval rules, no kernel change. */
final class StrictPurchaseApprovalPolicy implements DecisionPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}
    public function name(): string { return 'StrictPurchaseApproval'; }
    public function version(): int { return 1; }
    public function evaluate(array $c): Decision
    {
        return new Decision($c['tenantId'] ?? 't', $c['entityId'] ?? 'e', 'PurchaseApproval', 'policy',
            $this->name(), $this->name(), $this->version(), ['rule'=>'always_human'], [], $c['evidenceRefs'] ?? [],
            'requires human approval', 'Tenant policy: every purchase is human-approved', 100, true,
            $c['linkedRef'] ?? null, $this->clock, $this->ids);
    }
}
