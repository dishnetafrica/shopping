<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Decision;

interface DecisionPolicy
{
    public function name(): string;
    public function version(): int;                 // captured on every Decision for later explainability
    /** @param array<string,mixed> $context */
    public function evaluate(array $context): Decision;
}
