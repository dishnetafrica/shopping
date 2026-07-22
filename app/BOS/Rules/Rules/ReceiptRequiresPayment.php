<?php

namespace App\BOS\Rules\Rules;

use App\BOS\Rules\BusinessRule;

/** R2 — a receipt cannot exist without a payment. Context: ['payment_id' => ?int]. */
class ReceiptRequiresPayment implements BusinessRule
{
    public function code(): string { return 'R2'; }
    public function title(): string { return 'A receipt cannot exist without a payment.'; }

    public function passes(array $context): bool
    {
        return (int) ($context['payment_id'] ?? 0) > 0;
    }

    public function message(): string
    {
        return 'Cannot create a receipt: no payment is linked (R2).';
    }
}
