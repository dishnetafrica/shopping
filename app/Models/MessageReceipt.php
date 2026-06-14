<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One row per processed inbound WhatsApp message. The unique index
 * (tenant_id, whatsapp_message_id) makes processing idempotent: a duplicate
 * delivery / retry cannot be claimed twice.
 */
class MessageReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'conversation_id', 'whatsapp_message_id', 'processed_at'];
    protected $casts = ['processed_at' => 'datetime'];

    /**
     * Atomically claim a message id for processing.
     * @return bool true if this caller won the claim (first time), false if it was already processed.
     */
    public static function claim(int $tenantId, ?int $conversationId, string $whatsappMessageId): bool
    {
        $now = now();
        $inserted = DB::table('message_receipts')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'whatsapp_message_id' => $whatsappMessageId,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;   // 0 => unique-violation => duplicate
    }
}
