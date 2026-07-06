<?php declare(strict_types=1);
namespace CloudBSS\Modules\HR;

use CloudBSS\Kernel\Kernel;
use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** A non-financial domain plugging into the same kernel: Decision + Task, no ledger. */
final class LeaveModule
{
    public function __construct(private Kernel $k, private Clock $clock, private IdGenerator $ids) {}
    public function requestLeave(string $tenant, string $entity, string $employee, int $days)
    {
        $d = $this->k->decide(new LeaveApprovalPolicy($this->clock, $this->ids),
            ['tenantId'=>$tenant,'entityId'=>$entity,'days'=>$days,'linkedRef'=>$employee]);
        $this->k->recordEvent(new DomainEvent($this->ids->next('evt'), 'LeaveRequested', 1, 'Employee',
            $employee, ['days'=>$days, 'decision'=>$d->outcome], $this->clock->now(), $tenant, $entity));
        return $d;
    }
}
