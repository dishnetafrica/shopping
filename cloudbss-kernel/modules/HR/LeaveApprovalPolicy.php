<?php declare(strict_types=1);
namespace CloudBSS\Modules\HR;

use CloudBSS\Kernel\Decision\{Decision, DecisionPolicy};
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

final class LeaveApprovalPolicy implements DecisionPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}
    public function name(): string { return 'LeaveApproval'; }
    public function version(): int { return 1; }
    public function evaluate(array $c): Decision
    {
        $days = (int) $c['days']; $auto = $days <= 2;
        return new Decision($c['tenantId'], $c['entityId'], 'LeaveApproval', 'policy', $this->name(),
            $this->name(), $this->version(), ['autoUpToDays'=>2], ['days'=>$days], $c['evidenceRefs'] ?? [],
            $auto ? 'auto-approved' : 'requires manager approval',
            $auto ? "{$days}d within auto limit" : "{$days}d needs manager", $auto ? 88 : 100, !$auto,
            $c['linkedRef'] ?? null, $this->clock, $this->ids);
    }
}
