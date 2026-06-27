<?php
namespace App\Services\Knowledge\Dto;

use App\Services\Knowledge\Intent;
use App\Services\Knowledge\Reason;

/** What an Extractor returns for one message: an intent, plus Facts and/or Actions, plus leftovers. */
final class ExtractionResult
{
    /**
     * @param Fact[]          $facts
     * @param ActionRequest[] $actions
     * @param string[]        $leftovers  unparsed clauses — logged, never guessed
     */
    public function __construct(
        public string $intent = Intent::UNKNOWN,
        public string $reason = Reason::NONE,
        public array  $facts = [],
        public array  $actions = [],
        public array  $leftovers = [],
    ) {}

    public function isEmpty(): bool { return ! $this->facts && ! $this->actions; }

    public function merge(ExtractionResult $o): self
    {
        return new self(
            $this->intent !== Intent::UNKNOWN ? $this->intent : $o->intent,
            $this->reason !== Reason::NONE ? $this->reason : $o->reason,
            array_merge($this->facts, $o->facts),
            array_merge($this->actions, $o->actions),
            array_merge($this->leftovers, $o->leftovers),
        );
    }
}
