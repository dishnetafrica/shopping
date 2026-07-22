<?php

namespace App\BOS\Rules\Rules;

use App\BOS\Rules\BusinessRule;

/**
 * R3 — a SETTLEMENT payment applied to an invoice cannot exceed its outstanding balance; any excess
 * must become an advance/credit, never a negative balance. Advances are exempt (they aren't applied).
 * Context: ['kind' => 'settlement'|'advance', 'amount' => float, 'balance' => float].
 */
class PaymentNotExceedBalance implements BusinessRule
{
    public function code(): string { return 'R3'; }
    public function title(): string { return 'An applied payment cannot exceed the invoice balance.'; }

    public function passes(array $context): bool
    {
        $kind = strtolower((string) ($context['kind'] ?? 'settlement'));
        if ($kind !== 'settlement') {
            return true; // advances are not applied to a balance
        }
        $amount  = round((float) ($context['amount'] ?? 0), 2);
        $balance = round((float) ($context['balance'] ?? 0), 2);

        // A tiny epsilon tolerates rounding; excess is the caller's cue to book the remainder as advance.
        return $amount <= $balance + 0.005;
    }

    public function message(): string
    {
        return 'Applied payment exceeds the invoice balance; book the excess as an advance (R3).';
    }
}
