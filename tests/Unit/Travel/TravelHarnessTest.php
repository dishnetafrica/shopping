<?php

namespace Tests\Unit\Travel;

use PHPUnit\Framework\TestCase;

/**
 * Travel Vertical · M1 — the pure travel logic is specified by framework-free harnesses under qa/
 * (they also run in a sandbox with no Laravel install). This test runs each one inside CI, so the
 * same specification is a required check and there is exactly one copy of it.
 *
 * A harness prints one PASS/FAIL line per check and exits non-zero on any failure.
 */
class TravelHarnessTest extends TestCase
{
    private function harness(string $file): void
    {
        $path = dirname(__DIR__, 3) . '/qa/' . $file;
        $this->assertFileExists($path);
        $out = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        $failed = array_values(array_filter($out, fn ($l) => str_contains($l, '[FAIL]')));
        $this->assertSame(0, $code, $file . " failed:\n" . implode("\n", $failed ?: array_slice($out, -15)));
        $this->assertStringContainsString('ALL PASS', implode("\n", $out));
    }

    public function test_trip_record_merges_changes_without_losing_details(): void
    {
        $this->harness('travel_slots.php');
    }

    public function test_pipeline_allows_only_the_listed_moves(): void
    {
        $this->harness('travel_pipeline.php');
    }

    public function test_marker_reply_guard_and_passport_mask(): void
    {
        $this->harness('travel_guards.php');
    }

    public function test_ai_quality_definitions(): void
    {
        $this->harness('travel_metrics.php');
    }

    public function test_enquiry_brief_services_destinations_ages_budget_and_contact(): void
    {
        $this->harness('travel_brief.php');
    }

    public function test_staff_recipients_never_include_the_customer_facing_number(): void
    {
        $this->harness('travel_handoff.php');
    }
}
