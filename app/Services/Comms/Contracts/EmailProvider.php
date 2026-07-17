<?php

namespace App\Services\Comms\Contracts;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Services\Comms\ProviderResult;

/**
 * One transport implementation (SMTP / Mailgun / SendGrid / Postmark / Brevo).
 * A provider knows NOTHING about queues, templates or failover — it takes a
 * fully-rendered CommMessage plus the from-identity and either delivers it or
 * returns a failure. The dispatcher owns retries and failover.
 */
interface EmailProvider
{
    /**
     * @param array $from ['email' => ..., 'name' => ..., 'reply_to' => ...]
     */
    public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult;
}
