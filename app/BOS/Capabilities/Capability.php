<?php

namespace App\BOS\Capabilities;

/**
 * A business capability is a VERB — what the company can do (Sell, Buy, Move, Collect, Support,
 * Market, Manage, Analyze). It is the apex of the model (doc 1) and the governance anchor: every
 * feature declares the capability it serves. Capabilities orchestrate processes + objects; they
 * evolve the existing App\Capability layer rather than replace it (ADR-009).
 */
interface Capability
{
    /** Stable id, e.g. 'sell'. */
    public function id(): string;

    /** Why this capability exists (its mission line). */
    public function purpose(): string;

    /** Process ids this capability owns. */
    public function processes(): array;

    /** Object types this capability owns (creates/changes the core state of). */
    public function objects(): array;

    /** Permission menu key gating who may use it (reuses the existing permission model). */
    public function permission(): string;
}
