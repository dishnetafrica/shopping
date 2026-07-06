<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Decision\Policies;

use CloudBSS\Kernel\Decision\{Decision, DecisionPolicy};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** Auto-approve spend at/under threshold; otherwise defer to a human. */
final class PurchaseApprovalPolicy implements DecisionPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}
    public function name(): string { return 'PurchaseApproval'; }
    public function version(): int { return 1; }

    public function evaluate(array $context): Decision
    {
        /** @var Money $amount */ $amount = $context['amount'];
        /** @var Money $threshold */ $threshold = $context['threshold'];
        $auto = !$threshold->lessThan($amount);

        return new Decision(
            $context['tenantId'] ?? 't_default', $context['entityId'] ?? 'e_default',
            'PurchaseApproval', 'policy', $this->name(), $this->name(), $this->version(),
            ['thresholdMinor' => $threshold->minor, 'currency' => $threshold->currency->code],
            ['amountMinor' => $amount->minor, 'currency' => $amount->currency->code],
            $context['evidenceRefs'] ?? [],
            $auto ? 'auto-approved' : 'requires human approval',
            $auto ? "Within {$threshold->format()} auto-approval limit" : "{$amount->format()} exceeds {$threshold->format()} — escalate",
            $auto ? 95 : 100, !$auto, $context['linkedRef'] ?? null, $this->clock, $this->ids
        );
    }
}
