<?php

namespace App\BOS\Rules;

/** Thrown when an enforced business rule is violated. */
class RuleViolation extends \RuntimeException
{
    public function __construct(public string $ruleCode, string $message)
    {
        parent::__construct($message);
    }
}
