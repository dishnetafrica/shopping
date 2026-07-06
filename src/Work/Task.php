<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Work;

use CloudBSS\Kernel\Event\{DomainEvent, RecordedEvent};
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * Event-sourced. Every assignment and status change is an event, so the full
 * history replays. Public methods (assignTo/accept/start/complete/escalate) are
 * preserved — they now guard, RAISE an event, and APPLY it.
 */
final class Task
{
    private const FLOW = [
        'created'    => ['assigned', 'cancelled'],
        'assigned'   => ['accepted', 'reassigned', 'escalated', 'cancelled'],
        'reassigned' => ['accepted', 'escalated', 'cancelled'],
        'accepted'   => ['in_progress', 'escalated', 'cancelled'],
        'in_progress'=> ['completed', 'blocked', 'escalated'],
        'blocked'    => ['in_progress', 'escalated'],
        'escalated'  => ['assigned', 'cancelled'],
    ];

    public readonly string $id;
    public readonly string $tenantId;
    public readonly string $entityId;
    private string $status = 'created';
    private string $ownerId = '';
    private int $version = 0;
    /** @var DomainEvent[] uncommitted events awaiting the store */
    private array $pending = [];
    private ?Clock $clock; private ?IdGenerator $ids;

    private function __construct(string $id, string $tenantId, string $entityId)
    { $this->id = $id; $this->tenantId = $tenantId; $this->entityId = $entityId; }

    /** Open a new task (raises TaskOpened). */
    public static function open(string $tenantId, string $entityId, string $taskType, string $subject, ?string $contextRef, Clock $clock, IdGenerator $ids): self
    {
        $t = new self($ids->next('task'), $tenantId, $entityId);
        $t->clock = $clock; $t->ids = $ids;
        $t->raise('TaskOpened', ['taskType' => $taskType, 'subject' => $subject, 'contextRef' => $contextRef]);
        return $t;
    }

    /** Rebuild from its event history — proves the task is fully reconstructable. */
    public static function fromHistory(array $recordedEvents): self
    {
        $first = $recordedEvents[0]->event;
        $t = new self($first->aggregateId, $first->tenantId, $first->entityId);
        $t->clock = null; $t->ids = null;
        foreach ($recordedEvents as $rec) $t->apply($rec->event);
        return $t;
    }

    private function raise(string $name, array $payload): void
    {
        $e = new DomainEvent(
            ($this->ids?->next('evt')) ?? 'evt', $name, 1, 'Task', $this->id, $payload,
            $this->clock?->now() ?? new \DateTimeImmutable(), $this->tenantId, $this->entityId
        );
        $this->apply($e);
        $this->pending[] = $e;
    }

    private function apply(DomainEvent $e): void
    {
        match ($e->name) {
            'TaskOpened'     => $this->status = 'created',
            'TaskAssigned'   => [$this->status = 'assigned', $this->ownerId = $e->payload['ownerId']],
            'TaskReassigned' => [$this->status = 'reassigned', $this->ownerId = $e->payload['ownerId']],
            'TaskAccepted'   => $this->status = 'accepted',
            'TaskStarted'    => $this->status = 'in_progress',
            'TaskCompleted'  => $this->status = 'completed',
            'TaskEscalated'  => [$this->status = 'escalated', $this->ownerId = $e->payload['toOwnerId']],
            'TaskCancelled'  => $this->status = 'cancelled',
            default => null,
        };
        $this->version++;
    }

    private function guard(string $to): void
    {
        if (!in_array($to, self::FLOW[$this->status] ?? [], true))
            throw new \DomainException("Illegal task transition {$this->status} -> {$to}");
    }

    public function assignTo(string $participantId): void { $this->guard('assigned');  $this->raise('TaskAssigned', ['ownerId' => $participantId]); }
    public function accept(): void   { $this->guard('accepted');    $this->raise('TaskAccepted', []); }
    public function start(): void    { $this->guard('in_progress'); $this->raise('TaskStarted', []); }
    public function complete(): void { $this->guard('completed');   $this->raise('TaskCompleted', []); }
    public function escalate(string $toParticipantId): void { $this->guard('escalated'); $this->raise('TaskEscalated', ['toOwnerId' => $toParticipantId]); }

    public function status(): string { return $this->status; }
    public function owner(): string { return $this->ownerId; }
    public function version(): int { return $this->version; }
    /** @return DomainEvent[] */ public function pullPending(): array { $e = $this->pending; $this->pending = []; return $e; }
}
