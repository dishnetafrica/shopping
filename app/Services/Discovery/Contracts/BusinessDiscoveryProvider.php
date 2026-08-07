<?php declare(strict_types=1);

namespace App\Services\Discovery\Contracts;

use App\Services\Discovery\Dto\BusinessFinding;

/**
 * v4.59 — a source of businesses.
 *
 * Contract, and a strict one, copied from `ResearchProvider` because that discipline is the reason
 * the product-research pipeline does not fill with plausible rubbish:
 *
 *   - Return a finding ONLY for a field the source actually knows. Silence is a valid and preferred
 *     answer; a guess is not. Everything downstream reads a finding as "this source asserts this",
 *     so one filler value poisons a whole review queue.
 *
 *   - NEVER throw. A dead endpoint, a rate limit, a timeout, malformed JSON, a CSV with a byte-order
 *     mark — all return []. One provider having a bad day must not take down a discovery run that
 *     three other providers are contributing to.
 *
 *   - Say when you are not configured. `isEnabled()` false is how a provider with no API key stays
 *     out of the way instead of failing on every run.
 *
 * The long name is deliberate. `SupplierProvider` and `MarketResearchProvider` are coming, and the
 * registry should not need renaming when they arrive.
 */
interface BusinessDiscoveryProvider
{
    /** Registry key — also the value stamped on every finding's `source`. */
    public function key(): string;

    /** What a person calls this source. */
    public function label(): string;

    /** Configured and usable right now. */
    public function isEnabled(): bool;

    /**
     * @param  array  $query  ['area' => 'Juba - Gudele', 'category' => 'supermarket', 'radius_km' => 2,
     *                         'csv' => '<raw csv>', 'limit' => 50] — providers ignore what they do not use
     *
     * @return array<int,array{key:string, findings:BusinessFinding[]}>  one entry per business found;
     *          `key` is the provider's own idea of identity within this run (a row number, a place id),
     *          used only to group findings — never to identify the business in the CRM.
     */
    public function discover(array $query): array;
}
