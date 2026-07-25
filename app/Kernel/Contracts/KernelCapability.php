<?php declare(strict_types=1);

namespace App\Kernel\Contracts;

/**
 * A fine-grained business capability — the ONE actuator for a single business effect.
 *
 * Constitution Art. IV ("capabilities are the only actuators") and Engineering Principle 1
 * ("one business effect → one capability"). A capability's only side effect is emitting events;
 * it validates its invariants first and never mutates an object directly (Constitution Law 3).
 *
 * This command-level contract sits UNDER the coarse App\BOS\Capabilities\Capability "verb"
 * (e.g. Collect): per ADR-009 we evolve the existing App\Capability layer, we do not replace it.
 *
 * Ticket 3A introduces this contract; it is implemented by ReceiveMoney and exercised only by tests.
 */
interface KernelCapability
{
    /** Stable dotted key, e.g. 'money.receive' — the Rule-of-One owner of its effect. */
    public function key(): string;

    /** Event names this capability may emit (its half of the event contract). */
    public function emits(): array;

    /** Business-rule codes it enforces before emitting (App\BOS\Rules\RuleRegistry codes, e.g. ['R3']). */
    public function invariants(): array;

    /** 'reversible' | 'irreversible'. */
    public function effect(): string;

    /** The capability key that compensates (undoes) this one, or null. Undo is a new event, never a delete. */
    public function compensator(): ?string;

    /** 'human_only' | 'eligible' — whether the AI may ever run this alone (Constitution Art. V). */
    public function autonomy(): string;

    /**
     * Validate invariants, then emit events. MUST NOT mutate an object directly — its only
     * side effect is the emitted event(s).
     *
     * @param  array  $input  the intent's typed input
     * @return array{ok:bool, event_id?:string, errors?:array<string>}
     */
    public function run(array $input): array;
}
