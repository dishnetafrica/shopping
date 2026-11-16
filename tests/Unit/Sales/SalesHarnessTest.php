<?php

namespace Tests\Unit\Sales;

use PHPUnit\Framework\TestCase;

/**
 * Sales team — the pure rules are specified by framework-free harnesses under qa/ (they also run in a
 * sandbox with no Laravel install). This runs them in CI as a required check.
 */
class SalesHarnessTest extends TestCase
{
    public function test_new_customers_go_to_salespeople_in_turn(): void
    {
        $out = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/qa/sales_ownership.php') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", array_slice($out, -15)));
        $this->assertStringContainsString('ALL PASS', implode("\n", $out));
    }
}
