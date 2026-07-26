<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Project Advisor — the generic discovery contract.
 *
 * A discovery profile is the "front half" of an advisor: it declares WHAT to ask (a data-driven
 * questionnaire schema the UI renders) and knows how to turn the captured answers into the neutral
 * "brief" that a {@see SizingEngine} consumes. Solar is the first profile; generators / HVAC / water
 * treatment add their own profile + engine and reuse the entire framework.
 *
 * Both methods are pure (no DB, no I/O) so the questionnaire and the answer→brief mapping are fully
 * unit-testable and deterministic.
 */
interface DiscoveryProfile
{
    /** Stable key, e.g. 'solar'. Pairs with the matching SizingEngine. */
    public function key(): string;

    /**
     * The questionnaire definition the wizard renders: a title plus ordered sections, each with typed
     * fields (text | number | enum | boolean | loads). Enum fields carry `options`; `loads` carries
     * `item_fields`. This is data, not code — a new industry ships a different schema, not new UI.
     *
     * @return array{profile:string,title:string,sections:array<int,array<string,mixed>>}
     */
    public function schema(): array;

    /**
     * Map captured answers to the engine brief. Pure and defensive: unknown/blank answers are ignored,
     * numbers are coerced, and the output matches the shape the paired SizingEngine expects.
     *
     * @param array $answers  Raw answers keyed by field key.
     * @return array          The engine brief.
     */
    public function toBrief(array $answers): array;
}
