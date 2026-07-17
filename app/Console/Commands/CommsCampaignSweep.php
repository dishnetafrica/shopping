<?php

namespace App\Console\Commands;

use App\Services\Comms\CampaignService;
use Illuminate\Console\Command;

/**
 * comms:campaign-sweep — once a minute, enqueue the next throttle-sized batch
 * for every due campaign (status scheduled/sending, scheduled_at in the past).
 * The actual sends then flow through the normal comms pipeline (jobs + the
 * email sweep), so a campaign never bypasses suppression / failover / limits.
 * Cron-chain rule: only ever `return`s — never exit().
 */
class CommsCampaignSweep extends Command
{
    protected $signature   = 'comms:campaign-sweep';
    protected $description = 'Enqueue the next batch for every due Communication Center campaign';

    public function handle(CampaignService $svc): int
    {
        $n = $svc->processDue();
        $this->info("enqueued {$n} campaign message(s)");

        return self::SUCCESS;
    }
}
