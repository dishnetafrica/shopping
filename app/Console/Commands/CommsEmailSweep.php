<?php

namespace App\Console\Commands;

use App\Jobs\SendCommEmail;
use App\Models\CommMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * comms:email-sweep — the heartbeat of the email queue. Runs every minute.
 *
 *  1. Un-sticks messages stuck in `sending` > 10 min (worker died mid-send)
 *     back to `retry` so they re-enter the pipeline.
 *  2. Dispatches every DUE message: pending/retry whose scheduled_at and
 *     next_attempt_at are in the past (or null). Batch-capped at 200/run.
 *
 * With QUEUE_CONNECTION=sync this sweep IS the sender (no worker needed —
 * matches the EasyPanel deployment). With Horizon it just tops up the queue.
 * IMPORTANT (cron-chain rule): this command only ever `return`s — never exit().
 */
class CommsEmailSweep extends Command
{
    protected $signature   = 'comms:email-sweep {--limit=200}';
    protected $description = 'Dispatch due Communication Center emails (scheduled + retries) and recover stuck sends';

    public function handle(): int
    {
        // 1) Recover zombies.
        $stuck = DB::table('comm_messages')
            ->where('status', CommMessage::ST_SENDING)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => CommMessage::ST_RETRY, 'next_attempt_at' => now(), 'updated_at' => now()]);
        if ($stuck) $this->info("recovered {$stuck} stuck message(s)");

        // 2) Dispatch due work — channel-aware (M3): each row goes to its own job.
        $due = CommMessage::withoutGlobalScope('tenant')
            ->whereIn('status', [CommMessage::ST_PENDING, CommMessage::ST_RETRY])
            ->where(function ($q) { $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()); })
            ->where(function ($q) { $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()); })
            // pending rows younger than 30s were just dispatched inline — skip to avoid double work
            ->where(function ($q) { $q->where('status', CommMessage::ST_RETRY)->orWhere('created_at', '<=', now()->subSeconds(30)); })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'channel']);

        foreach ($due as $row) {
            if ($row->channel === 'whatsapp') {
                \App\Jobs\SendCommWhatsApp::dispatch($row->id);
            } else {
                SendCommEmail::dispatch($row->id);
            }
        }
        $this->info('dispatched '.count($due).' message(s)');

        return self::SUCCESS;
    }
}
