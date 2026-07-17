<?php

namespace App\Jobs;

use App\Services\Comms\WhatsAppDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one channel='whatsapp' comm_messages row. $tries = 1 on purpose —
 * retry/backoff live inside WhatsAppDispatcher against the row (identical
 * semantics to SendCommEmail).
 */
class SendCommWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $messageId)
    {
    }

    public function handle(WhatsAppDispatcher $dispatcher): void
    {
        $dispatcher->attempt($this->messageId);
    }
}
