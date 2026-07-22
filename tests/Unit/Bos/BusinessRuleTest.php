<?php

namespace Tests\Unit\Bos;

use App\BOS\Rules\Rules\NoHardDeleteFinancial;
use App\BOS\Rules\Rules\PaymentNotExceedBalance;
use App\BOS\Rules\Rules\ReceiptRequiresPayment;
use App\BOS\Rules\RuleRegistry;
use App\BOS\Rules\RuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Named business-rule tests (doc 4). Every rule Rn MUST have a test_Rn_* here — the fitness test
 * enforces the mapping. Pure: no DB, no app boot.
 */
class BusinessRuleTest extends TestCase
{
    public function test_R2_receipt_requires_payment(): void
    {
        $rule = new ReceiptRequiresPayment();
        $this->assertTrue($rule->passes(['payment_id' => 42]));
        $this->assertFalse($rule->passes([]));
        $this->assertFalse($rule->passes(['payment_id' => 0]));
    }

    public function test_R3_payment_not_exceed_balance(): void
    {
        $rule = new PaymentNotExceedBalance();
        // settlement within balance passes; over balance fails; advance is exempt
        $this->assertTrue($rule->passes(['kind' => 'settlement', 'amount' => 100, 'balance' => 100]));
        $this->assertTrue($rule->passes(['kind' => 'settlement', 'amount' => 60, 'balance' => 100]));
        $this->assertFalse($rule->passes(['kind' => 'settlement', 'amount' => 120, 'balance' => 100]));
        $this->assertTrue($rule->passes(['kind' => 'advance', 'amount' => 500, 'balance' => 100]));
    }

    public function test_R4_void_not_hard_delete(): void
    {
        $rule = new NoHardDeleteFinancial();
        $this->assertFalse($rule->passes(['object' => 'invoice', 'operation' => 'hard_delete']));
        $this->assertTrue($rule->passes(['object' => 'invoice', 'operation' => 'void', 'reason' => 'duplicate']));
        $this->assertFalse($rule->passes(['object' => 'invoice', 'operation' => 'void', 'reason' => '']));
        $this->assertTrue($rule->passes(['object' => 'note', 'operation' => 'hard_delete'])); // unprotected object
    }

    public function test_registry_enforce_throws_on_violation(): void
    {
        $reg = RuleRegistry::default();
        $reg->enforce('R3', ['kind' => 'settlement', 'amount' => 10, 'balance' => 50]); // ok, no throw

        $this->expectException(RuleViolation::class);
        $reg->enforce('R2', []); // receipt with no payment
    }
}
