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
        ] + self::senderColumn($sender));

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

        // Phones buzz on new inbound — browser closed included (Web Push). Resolved via
        // the container so tests can fake it; fail-soft so a push can NEVER break logging.
        if ($direction === 'in') {
            try {
                $preview = trim($body) !== '' ? $body : 'sent a message';
                app(\App\Services\Push\WebPushService::class)
                    ->notifyInbound($tenantId, $phone, $phone, $preview);
            } catch (\Throwable $e) {
                // never let push failures touch the transcript
            }
        }

        return $msg;
    }

    /**
     * Who on the team sent it: a reply typed in the panel is logged as 'agent' while a staff member is
     * signed in. Bot, customer and phone-typed ('human') messages stay unattributed. The column is
     * only written when there is someone to record AND it exists, so logging can never fail on it.
     */
    private static function senderColumn(string $sender): array
    {
        static $hasColumn = null;
        try {
            if ($sender !== 'agent' || ($id = auth()->id()) === null) {
                return [];
            }
            $hasColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('messages', 'sender_user_id');

            return $hasColumn ? ['sender_user_id' => (int) $id] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
