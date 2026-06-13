<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Message;

/**
 * The one place a message gets written. Every inbound/outbound path calls this,
 * so the transcript is complete and the inbox list (last message, unread badge,
 * who-wrote-last) stays correct without each caller remembering to update it.
 */
class MessageLog
{
    public static function record(
        int $tenantId,
        string $phone,
        ?string $instance,
        string $direction,   // 'in' | 'out'
        string $sender,      // customer | bot | agent | system
        string $body,
        ?string $waId = null,
        ?string $status = null,
        array $meta = []
    ): Message {
        $msg = Message::create([
            'tenant_id'     => $tenantId,
            'customer_phone' => $phone,
            'instance'      => $instance,
            'direction'     => $direction,
            'sender'        => $sender,
            'body'          => $body,
            'wa_message_id' => $waId,
            'status'        => $status,
            'meta'          => $meta ?: null,
        ]);

        $convo = Conversation::firstOrCreate(
            ['customer_phone' => $phone, 'instance' => $instance],
            ['tenant_id' => $tenantId, 'state' => [], 'cart' => []]
        );
        $convo->last_message_at = now();
        if ($direction === 'in') {
            $convo->last_inbound_at = now();
            $convo->unread = (int) $convo->unread + 1;   // badge it for staff
        } else {
            $convo->unread = 0;                           // bot/agent answered
        }
        $convo->save();

        return $msg;
    }
}
