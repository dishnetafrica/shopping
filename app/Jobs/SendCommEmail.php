<?php

namespace App\Jobs;

use App\Services\Comms\EmailDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one comm_messages row. Deliberately $tries = 1: retry/backoff/failover
 * semantics live INSIDE EmailDispatcher (comm_messages.attempts), not in the
 * queue layer — so behaviour is identical on Horizon, database queue, or the
 * sync driver (EasyPanel deployments with no worker still send via the sweeper).
 */
class SendCommEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $messageId)
    {
    }

    public function handle(EmailDispatcher $dispatcher): void
    {
        $dispatcher->attempt($this->messageId);
    }
}
