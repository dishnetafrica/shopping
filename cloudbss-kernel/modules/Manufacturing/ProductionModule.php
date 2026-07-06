<?php declare(strict_types=1);
namespace CloudBSS\Modules\Manufacturing;

use CloudBSS\Kernel\Kernel;
use CloudBSS\Kernel\Event\{DomainEvent, EventBus, RecordedEvent};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Work\Task;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** The whole module: subscribes to the bus, uses Decision/Task/Event/Money/Ledger via the kernel API only. */
final class ProductionModule
{
    /** @var Task[] */ public array $tasks = [];
    public function __construct(private Kernel $k, private Clock $clock, private IdGenerator $ids) {}

    public function subscribe(EventBus $bus): void
    {
        // when a production order is released, raise the shop-floor task — kernel unchanged
        $bus->subscribe('ProductionOrderReleased', function (RecordedEvent $rec) {
            $e = $rec->event;
            $t = Task::open($e->tenantId, $e->entityId, 'run_production', "Produce {$e->aggregateId}", $e->aggregateId, $this->clock, $this->ids);
            $this->k->persist($t);
            $this->tasks[] = $t;
        });
    }

    public function releaseOrder(string $tenant, string $entity, string $poId, Money $estCost, Money $limit)
    {
        $d = $this->k->decide(new ProductionApprovalPolicy($this->clock, $this->ids),
            ['tenantId'=>$tenant,'entityId'=>$entity,'estCost'=>$estCost,'limit'=>$limit,'linkedRef'=>$poId]);
        if (!$d->requiresHuman) {
            $this->k->recordEvent(new DomainEvent($this->ids->next('evt'), 'ProductionOrderReleased', 1,
                'ProductionOrder', $poId, ['estCostMinor'=>$estCost->minor], $this->clock->now(), $tenant, $entity));
        }
        return $d;
    }

    public function completeProduction(string $tenant, string $entity, string $poId, Money $cost): void
    {
        $this->k->recordEvent(new DomainEvent($this->ids->next('evt'), 'GoodsProducedToStock', 1,
            'ProductionOrder', $poId, ['cost'=>$cost->minor, 'currency'=>$cost->currency->code], $this->clock->now(), $tenant, $entity));
    }
}
