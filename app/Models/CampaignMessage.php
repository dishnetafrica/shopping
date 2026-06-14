<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One row per (campaign, recipient). The unique index makes campaign sending
 * idempotent: a retried/restarted SendCampaign job will skip recipients that
 * already have a row, so no one is messaged twice.
 */
class CampaignMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'campaign_id', 'recipient', 'message_id', 'status', 'sent_at'];
    protected $casts = ['sent_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** Claim a recipient for this campaign. true = send now, false = already handled. */
    public static function claim(int $tenantId, int $campaignId, string $recipient): bool
    {
        $now = now();
        $inserted = DB::table('campaign_messages')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'campaign_id' => $campaignId,
            'recipient' => $recipient,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;
    }

    public static function markSent(int $campaignId, string $recipient, ?string $messageId, string $status = 'sent'): void
    {
        DB::table('campaign_messages')
            ->where('campaign_id', $campaignId)->where('recipient', $recipient)
            ->update(['message_id' => $messageId, 'status' => $status, 'sent_at' => now(), 'updated_at' => now()]);
    }
}
