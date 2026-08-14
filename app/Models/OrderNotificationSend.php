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
        if ($inserted > 0) {
            return true;
        }

        // v5.05 — a row already exists. If it was SENT, never touch it: that is the whole point of
        // the ledger. If it is an unsent claim older than the retry window it is the wreckage of a
        // failed attempt, not a live one, so a later run may take it over. This is what lets a
        // failed send keep its row (see markFailed) without becoming permanently stuck.
        return DB::table('order_notification_sends')
            ->where('order_id', $orderId)->where('recipient_id', $recipientId)->where('event_type', $eventType)
            ->whereNull('sent_at')
            ->where('created_at', '<', now()->subMinutes(10))
            ->update(['created_at' => now()]) > 0;
    }

    public static function markSent(int $orderId, int $recipientId, string $eventType, ?string $messageId): void
    {
        DB::table('order_notification_sends')
            ->where('order_id', $orderId)->where('recipient_id', $recipientId)->where('event_type', $eventType)
            ->update(['sent_at' => now(), 'message_id' => $messageId]);
    }

    /**
     * v5.05 — the send failed. LEAVE THE ROW, with sent_at NULL.
     *
     * The old behaviour (releaseClaim, below) deleted it, which meant a lost notification left no
     * evidence it had ever been attempted — nothing to query, nothing to alert on. Keeping the row
     * makes `where sent_at is null` the answer to "which orders did nobody hear about", and
     * claim() above re-takes a stale unsent row so retrying is still possible.
     */
    public static function markFailed(int $orderId, int $recipientId, string $eventType): void
    {
        DB::table('order_notification_sends')
            ->where('order_id', $orderId)->where('recipient_id', $recipientId)->where('event_type', $eventType)
            ->whereNull('sent_at')
            ->update(['created_at' => now()->subMinutes(11)]);   // immediately eligible for a retry
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
