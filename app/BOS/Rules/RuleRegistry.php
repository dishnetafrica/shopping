<?php

namespace App\BOS\Rules;

/**
 * The single source of policy. Every rule is registered here exactly once; nothing outside this
 * registry re-implements a rule (Implementation Constitution, doc 9). Call check() for a boolean or
 * enforce() to throw a RuleViolation. New rules are added here + the doc-4 catalogue + a named test.
 */
class RuleRegistry
{
    /** @var array<string,BusinessRule> code => rule */
    private array $rules = [];

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= self::default();
    }

    public static function default(): self
    {
        $r = new self();
        $r->register(new Rules\ReceiptRequiresPayment());   // R2
        $r->register(new Rules\PaymentNotExceedBalance());  // R3
        $r->register(new Rules\NoHardDeleteFinancial());    // R4
        // Further rules (R1, R6–R24) are registered here as they are implemented, one per class.
        return $r;
    }

    public function register(BusinessRule $rule): void
    {
        $this->rules[$rule->code()] = $rule;
    }

    /** @return array<string,BusinessRule> */
    public function all(): array
    {
        return $this->rules;
    }

    public function rule(string $code): ?BusinessRule
    {
        return $this->rules[$code] ?? null;
    }

    public function check(string $code, array $context): bool
    {
        $rule = $this->rule($code);
        if (! $rule) {
            throw new \InvalidArgumentException("Unknown business rule: {$code}");
        }
        return $rule->passes($context);
    }

    /** Throws RuleViolation if the rule fails. */
    public function enforce(string $code, array $context): void
    {
        $rule = $this->rule($code);
        if (! $rule) {
            throw new \InvalidArgumentException("Unknown business rule: {$code}");
        }
        if (! $rule->passes($context)) {
            throw new RuleViolation($code, $rule->message());
        }
    }
}
