<?php

namespace App\Services\Research\Contracts;

use App\Services\Research\Dto\Finding;

/**
 * A source of product facts.
 *
 * Contract, and it is a strict one:
 *   - return a Finding ONLY for a field the source actually knows. Silence is a
 *     valid and preferred answer; a guess is not. Everything downstream assumes
 *     a Finding means "this source asserts this", so a filler value poisons the
 *     whole pipeline.
 *   - NEVER throw. A dead endpoint, a timeout, malformed JSON — all return [].
 *     Research is an enhancement; it must not be able to break adding a product.
 */
interface ResearchProvider
{
    /** Registry key — also the value stored in Finding::$source. */
    public function key(): string;

    /** Configured and usable right now. */
    public function isEnabled(): bool;

    /**
     * @param  array  $product  ['name'=>, 'barcode'=>, 'brand'=>, 'category'=>, 'unit_size'=>, 'origin_country'=>, 'description'=>]
     * @param  string[]  $wanted  field names still unknown — a provider may ignore this and
     *                            return more; the orchestrator filters.
     * @return Finding[]
     */
    public function research(array $product, array $wanted): array;
}
