<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Durable ledger of order notifications. The unique index
 * (order_id, recipient_id, event_type) makes sending idempotent — a duplicate
 * order event / job retry cannot notify the same recipient twice — and provides
 * an audit trail (sent_at, message_id) for every successful send.
 */
class OrderNotificationSend extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;   // ledger has created_at only

    protected $fillable = ['tenant_id', 'order_id', 'recipient_id', 'event_type', 'sent_at', 'message_id'];
    protected $casts = ['sent_at' => 'datetime'];

    /**
     * Atomically claim (order, recipient, event). Returns true only for the
     * caller that won the claim; a duplicate returns false.
     */
    public static function claim(int $tenantId, int $orderId, int $recipientId, string $eventType = 'order_placed'): bool
    {
        $inserted = DB::table('order_notification_sends')->insertOrIgnore([
            'tenant_id'    => $tenantId,
            'order_id'     => $orderId,
            'recipient_id' => $recipientId,
            'event_type'   => $eventType,
            'created_at'   => now(),
        ]);

        return $inserted > 0;
    }

    public static function markSent(int $orderId, int $recipientId, string $eventType, ?string $messageId): void
    {
        DB::table('order_notification_sends')
            ->where('order_id', $orderId)->where('recipient_id', $recipientId)->where('event_type', $eventType)
            ->update(['sent_at' => now(), 'message_id' => $messageId]);
    }

    /** Send failed — drop the claim so a retry can attempt again (no duplicate, never stuck). */
    public static function releaseClaim(int $orderId, int $recipientId, string $eventType): void
    {
        DB::table('order_notification_sends')
            ->where('order_id', $orderId)->where('recipient_id', $recipientId)->where('event_type', $eventType)
            ->whereNull('sent_at')
            ->delete();
    }
}
