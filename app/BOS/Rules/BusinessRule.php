<?php

namespace App\BOS\Rules;

/**
 * A business rule is policy. Rules live in ONE place (this layer) — never duplicated in screens or
 * services (Implementation Constitution, doc 9). Each rule maps to a doc-4 entry (R#) and a named
 * test (test_R#_…). A rule is pure: given a context, it passes or it doesn't.
 */
interface BusinessRule
{
    /** The catalogue id, e.g. 'R3'. */
    public function code(): string;

    /** One-line human statement. */
    public function title(): string;

    /** Pure check over a context array. No side effects, no DB required. */
    public function passes(array $context): bool;

    /** Why it failed (shown/logged when enforced). */
    public function message(): string;
}
