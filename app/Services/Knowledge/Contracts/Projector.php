<?php
namespace App\Services\Knowledge\Contracts;

use App\Models\KnowledgeAction;

/** Applies a confirmed action into this capability's operational projection. Owns its internals. */
interface Projector
{
    /** Apply one confirmed action; return a short human label for the summary/timeline. */
    public function apply(KnowledgeAction $action): string;

    /** Reverse a previously applied action (for undo). */
    public function revert(KnowledgeAction $action): void;
}
