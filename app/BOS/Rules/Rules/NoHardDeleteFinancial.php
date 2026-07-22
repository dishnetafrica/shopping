<?php

namespace App\BOS\Rules\Rules;

use App\BOS\Rules\BusinessRule;

/**
 * R4 — financial / customer-facing objects are never hard-deleted; they are voided/reversed/archived
 * (with a reason) and kept for audit. Context: ['operation' => 'void'|'archive'|'hard_delete',
 * 'object' => string, 'reason' => ?string].
 */
class NoHardDeleteFinancial implements BusinessRule
{
    private const PROTECTED = ['invoice', 'payment', 'receipt', 'order', 'quote', 'credit_note', 'cash_entry', 'expense', 'customer'];

    public function code(): string { return 'R4'; }
    public function title(): string { return 'Financial/customer objects are never hard-deleted (void instead).'; }

    public function passes(array $context): bool
    {
        $object = strtolower((string) ($context['object'] ?? ''));
        $op     = strtolower((string) ($context['operation'] ?? ''));

        if (! in_array($object, self::PROTECTED, true)) {
            return true; // rule only governs protected objects
        }
        if ($op === 'hard_delete') {
            return false;
        }
        // A void/archive must carry a reason.
        if (in_array($op, ['void', 'archive'], true)) {
            return trim((string) ($context['reason'] ?? '')) !== '';
        }
        return true;
    }

    public function message(): string
    {
        return 'This object cannot be hard-deleted; void or archive it with a reason (R4).';
    }
}
