<?php declare(strict_types=1);
namespace CloudBSS\Kernel;

use CloudBSS\Kernel\Decision\{Decision, DecisionPolicy};
use CloudBSS\Kernel\Event\{DomainEvent, EventStore, EventBus, RecordedEvent};
use CloudBSS\Kernel\Ledger\{AccountingPolicyResolver, Posting};
use CloudBSS\Kernel\Support\{Clock, IdGenerator};
use CloudBSS\Kernel\Work\Task;

/**
 * ONE write model. Everything flows:
 *   command -> Decision (the why) -> Event -> EventStore -> EventBus -> Projections
 *   business event -> [resolved AccountingPolicy] -> JournalPosted -> Ledger projection
 * The kernel is tenant-agnostic: tenant/entity ride as event metadata; the policy
 * RESOLVER (supplied by the app) picks the right accounting per event.
 */
final class Kernel
{
    /** @var Decision[] convenience index; the source of truth is the DecisionMade events */
    private array $decisions = [];

    public function __construct(
        private EventStore $store,
        private EventBus $bus,
        private Clock $clock,
        private IdGenerator $ids,
        private AccountingPolicyResolver $policies
    ) {
        // Posting reaction: any business event with a matching accounting policy -> JournalPosted.
        $this->bus->subscribeAll(function (RecordedEvent $rec): void {
            $e = $rec->event;
            if ($e->name === Posting::EVENT) return;                 // don't re-post postings
            $policy = $this->policies->policyFor($e);
            if ($policy && $policy->supports($e)) {
                $entry = $policy->deriveEntry($e);
                $this->recordEvent(Posting::toEvent(
                    $entry, $this->ids->next('evt'), $this->clock->now(),
                    causationId: $e->eventId, correlationId: $e->correlationId
                ));
            }
        });
    }

    /** Append a fact and fan it out. The single entry point to the write model. */
    public function recordEvent(DomainEvent $e, ?int $expectedVersion = null): RecordedEvent
    {
        $rec = $this->store->append($e, $expectedVersion);
        $this->bus->publish($rec);
        return $rec;
    }

    /** Run a decision policy, record the WHY in the log, and act on it. */
    public function decide(DecisionPolicy $policy, array $context): Decision
    {
        $d = $policy->evaluate($context);
        $this->decisions[] = $d;

        // the full why lives in the event log, forever explainable
        $this->recordEvent(new DomainEvent(
            $this->ids->next('evt'), 'DecisionMade', 1, 'Decision', $d->id,
            $d->toArray(), $this->clock->now(), $d->tenantId, $d->entityId,
            $context['correlationId'] ?? null
        ));

        if ($d->requiresHuman) {
            $t = Task::open($d->tenantId, $d->entityId, 'approve_' . strtolower($d->type), "Approve: {$d->outcome}", $d->linkedRef, $this->clock, $this->ids);
            $this->persist($t);
        }
        return $d;
    }

    /** Flush an event-sourced aggregate's pending events into the log. */
    public function persist(Task $t): void
    {
        foreach ($t->pullPending() as $e) $this->recordEvent($e);
    }

    /** @return Decision[] */ public function decisions(): array { return $this->decisions; }
    public function store(): EventStore { return $this->store; }
    public function bus(): EventBus { return $this->bus; }
}
