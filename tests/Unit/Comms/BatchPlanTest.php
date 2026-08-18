<?php

namespace Tests\Unit\Comms;

use App\Services\Comms\Marketing\BatchPlan;
use PHPUnit\Framework\TestCase;

/**
 * Per-campaign daily-batch maths: "send X/day, spread over the week." Pure unit test.
 */
class BatchPlanTest extends TestCase
{
    public function test_days(): void
    {
        $this->assertSame(0, BatchPlan::days(0, 40));         // no audience → no days
        $this->assertSame(1, BatchPlan::days(40, 40));        // exactly one day
        $this->assertSame(2, BatchPlan::days(41, 40));        // spills to a second day
        $this->assertSame(18, BatchPlan::days(700, 40));      // the 700-over-~2.5-weeks case
        $this->assertSame(1, BatchPlan::days(700, 0));        // perDay 0 → no batching, one send
        $this->assertSame(1, BatchPlan::days(5, 40));         // fewer than a day's worth
    }

    public function test_breakdown(): void
    {
        $this->assertSame([40, 40, 20], BatchPlan::breakdown(100, 40));
        $this->assertSame([40], BatchPlan::breakdown(40, 40));
        $this->assertSame([5], BatchPlan::breakdown(5, 40));
        $this->assertSame([], BatchPlan::breakdown(0, 40));
        // Sum of the breakdown equals the audience (nobody dropped, nobody double-counted).
        $this->assertSame(700, array_sum(BatchPlan::breakdown(700, 40, 100)));
        // Capped to maxDays so a tiny pace can't produce an unbounded array.
        $this->assertCount(14, BatchPlan::breakdown(100000, 1, 14));
    }
}
