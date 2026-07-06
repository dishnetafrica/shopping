<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;

use CloudBSS\Kernel\Support\{EnterpriseObject, Clock, IdGenerator};

/** A balanced set of lines. Value object of the posting; carried inside a JournalPosted event. */
final class JournalEntry extends EnterpriseObject
{
    /** @param JournalLine[] $lines */
    public function __construct(
        string $tenantId, string $entityId,
        public readonly string $memo,
        public readonly array $lines,
        public readonly ?string $sourceEventId = null,
        ?Clock $clock = null, ?IdGenerator $ids = null
    ) {
        parent::__construct($tenantId, $entityId, $clock, $ids);
        if (count($lines) < 2) throw new \DomainException('An entry needs at least two lines');
    }

    protected static function idPrefix(): string { return 'je'; }

    public function isBalanced(): bool
    {
        $bal = [];
        foreach ($this->lines as $l) {
            $c = $l->amount->currency->code;
            $bal[$c] = ($bal[$c] ?? 0) + ($l->side === 'debit' ? $l->amount->minor : -$l->amount->minor);
        }
        foreach ($bal as $net) if ($net !== 0) return false;
        return true;
    }
}
