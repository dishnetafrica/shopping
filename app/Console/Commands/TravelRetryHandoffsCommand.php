<?php

namespace App\Console\Commands;

use App\Services\Travel\Handoff\StaffHandoff;
use Illuminate\Console\Command;

/**
 * Retry travel-enquiry staff alerts that were not delivered (issue #145). Scheduled every five
 * minutes. Each alert is tried a limited number of times; after that it stays visible as failed on
 * the trip until a member of staff presses "Retry staff alert".
 */
class TravelRetryHandoffsCommand extends Command
{
    protected $signature = 'travel:retry-handoffs {--limit=50}';

    protected $description = 'Retry undelivered staff alerts for travel enquiries';

    public function handle(StaffHandoff $handoff): int
    {
        $delivered = $handoff->retryDue(max(1, (int) $this->option('limit')));
        $this->info("Delivered {$delivered} staff alert(s).");

        return self::SUCCESS;
    }
}
