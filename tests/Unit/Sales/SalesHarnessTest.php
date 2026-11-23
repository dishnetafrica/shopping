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

    public function test_the_bot_quotation_uses_only_visible_catalogue_products(): void
    {
        $this->harness('isp_autoquote.php');
    }

    public function test_past_customers_are_sorted_for_win_back_with_no_amount_in_the_first_message(): void
    {
        $this->harness('isp_winback.php');
    }

    public function test_quotation_follow_ups_carry_no_amount_and_stop_after_two(): void
    {
        $this->harness('isp_followup.php');
    }

    public function test_whatsapp_history_copy_reads_every_time_shape_and_never_duplicates(): void
    {
        $this->harness('history_sync.php');
    }

    public function test_a_missed_whatsapp_call_is_read_answered_and_alerted(): void
    {
        $this->harness('isp_missed_call.php');
    }

    public function test_a_hidden_number_customer_gets_a_number_staff_can_call(): void
    {
        $this->harness('isp_callback.php');
    }

    public function test_a_quiet_enquiry_is_followed_up_twice_without_amounts(): void
    {
        $this->harness('isp_chat_followup.php');
    }

    public function test_a_customer_with_a_problem_gets_the_support_number_at_once(): void
    {
        $this->harness('isp_support.php');
    }

    public function test_a_customer_who_asks_for_the_salesperson_gets_their_own(): void
    {
        $this->harness('isp_sales_contact.php');
    }

    public function test_a_discontinued_plan_leaves_sales_knowledge_and_nothing_else_does(): void
    {
        $this->harness('retire_mention.php');
    }

    public function test_sales_knowledge_is_governed_and_coverage_defaults_to_survey(): void
    {
        $this->harness('sales_brain.php');
    }

    public function test_internet_provider_bot_never_states_unapproved_prices_or_coverage(): void
    {
        $this->harness('isp_bot.php');
    }

    public function test_dishnet_bot_import_is_drafts_only_and_carries_no_staff_numbers(): void
    {
        $this->harness('dishnet_import.php');
    }

    public function test_sales_coach_suggestions_are_built_from_approved_knowledge_and_guarded(): void
    {
        $this->harness('sales_coach.php');
    }

    public function test_training_is_weighted_with_an_80_percent_pass_and_questions_are_governed(): void
    {
        $this->harness('sales_training.php');
    }

    public function test_pipeline_stages_are_gated_and_lost_needs_a_reason(): void
    {
        $this->harness('sales_pipeline.php');
    }

    public function test_dashboard_numbers_and_quality_rules(): void
    {
        $this->harness('sales_insights.php');
    }

    public function test_dishnet_management_decisions_apply_exactly(): void
    {
        $this->harness('dishnet_decisions.php');
    }

    public function test_a_burst_of_messages_never_leaves_one_unanswered_whatever_order_they_run_in(): void
    {
        $this->harness('burst_coalesce.php');
    }

    public function test_an_internet_customer_is_remembered_by_service_and_place_never_by_a_price(): void
    {
        $this->harness('isp_memory.php');
    }

    public function test_a_missed_reply_is_recovered_only_when_the_bot_never_finished(): void
    {
        $this->harness('missed_reply.php');
    }
}
