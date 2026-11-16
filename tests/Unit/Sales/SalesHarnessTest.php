<?php

namespace Tests\Unit\Sales;

use PHPUnit\Framework\TestCase;

/**
 * Sales team — the pure rules are specified by framework-free harnesses under qa/ (they also run in a
 * sandbox with no Laravel install). This runs them in CI as a required check.
 */
class SalesHarnessTest extends TestCase
{
    private function harness(string $file): void
    {
        $out = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/qa/' . $file) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, $file . ":\n" . implode("\n", array_slice($out, -15)));
        $this->assertStringContainsString('ALL PASS', implode("\n", $out));
    }

    public function test_new_customers_go_to_salespeople_in_turn(): void
    {
        $this->harness('sales_ownership.php');
    }

    public function test_internet_provider_quotation_keeps_one_time_and_monthly_apart(): void
    {
        $this->harness('isp_quote.php');
    }
}
