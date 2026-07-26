<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Project Advisor — the generic system-sizing contract.
 *
 * The Advisor framework is NOT solar-specific. Each industry (solar, generators, HVAC, water treatment,
 * networking…) provides its own SizingEngine implementation that turns a discovery "brief" (the answers
 * a salesperson captured) into a deterministic set of engineering quantities. The AI layer explains and
 * narrates these numbers; it never produces them. Swap the engine (+ discovery schema + proposal
 * template) and the same Advisor framework serves a new industry.
 *
 * Contract rules for every implementation:
 *   - Pure and deterministic: same brief + config → same result, always. No randomness, no clock, no I/O,
 *     no DB, no network. This is what makes recommendations technically defensible and unit-testable.
 *   - Config-driven: engineering assumptions (efficiencies, safety margin, expansion, local sun hours…)
 *     come from $config with documented defaults, so the same engine is reusable and tunable per tenant
 *     or per project without code changes.
 *   - Transparent: the result includes a `steps` breakdown (label, formula, value, unit) so the AI — and
 *     the customer-facing proposal — can show the working, not just the answer.
 */
interface SizingEngine
{
    /** Stable key for this engine/industry, e.g. 'solar'. Used to select the engine for a profile. */
    public function key(): string;

    /**
     * Compute the sizing result for one project.
     *
     * @param array $brief   Discovery answers (shape is engine-specific; solar uses `loads`,
     *                        `backup_hours`, `peak_sun_hours`, `expansion_pct`).
     * @param array $config  Engineering assumptions overriding the engine's defaults.
     * @return array         Structured result: the computed quantities, the applied assumptions, and an
     *                       ordered `steps` breakdown for explanation. Never throws on empty input —
     *                       returns zeroed quantities instead.
     */
    public function size(array $brief, array $config = []): array;
}
