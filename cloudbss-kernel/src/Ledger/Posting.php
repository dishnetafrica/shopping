<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Money\Money;

/** Turns a balanced JournalEntry into a JournalPosted event, and reads a posting back out. */
final class Posting
{
    public const EVENT = 'JournalPosted';

    /** Validate balance and author the fact. The ledger never stores; it projects this event. */
    public static function toEvent(
        JournalEntry $entry, string $eventId, \DateTimeImmutable $at,
        ?string $causationId = null, ?string $correlationId = null
    ): DomainEvent {
        if (!$entry->isBalanced()) {
            throw new \DomainException("Refused unbalanced entry: {$entry->memo}");
        }
        $lines = array_map(fn(JournalLine $l) => [
            'code' => $l->account->code, 'name' => $l->account->name, 'type' => $l->account->type,
            'side' => $l->side, 'minor' => $l->amount->minor, 'currency' => $l->amount->currency->code,
        ], $entry->lines);

        return new DomainEvent(
            $eventId, self::EVENT, 1, 'Ledger', $entry->id,
            ['memo' => $entry->memo, 'sourceEventId' => $entry->sourceEventId, 'lines' => $lines],
            $at, $entry->tenantId, $entry->entityId, $correlationId, $causationId
        );
    }
}
