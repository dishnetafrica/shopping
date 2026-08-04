<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Do-not-contact list. Checked before every enqueue; written by webhooks
 * (hard bounce / complaint), the unsubscribe page, or an operator.
 */
class CommSuppression extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'channel', 'address', 'reason', 'source'];

    /**
     * v4.32 — normalise per channel.
     *
     * An address used to be stored and compared as `mb_strtolower(trim(...))` whatever the
     * channel. That is right for email and wrong for a phone number: an owner typing
     * "+256 772 123 456" stored it verbatim, while WhatsApp addresses are written as bare
     * digits — so the row existed, looked correct on screen, and never matched anything.
     * Phone channels are reduced to digits on BOTH write and read.
     */
    public static function normalise(string $address, string $channel = 'email'): string
    {
        if (in_array($channel, ['whatsapp', 'sms'], true)) {
            return preg_replace('/[^0-9]/', '', $address) ?? '';
        }

        return mb_strtolower(trim($address));
    }

    public static function isSuppressed(int $tenantId, string $address, string $channel = 'email'): bool
    {
        return DB::table('comm_suppressions')
            ->where('tenant_id', $tenantId)->where('channel', $channel)
            ->where('address', self::normalise($address, $channel))
            ->exists();
    }

    /** Idempotent insert (unique index makes duplicates a no-op). */
    public static function add(int $tenantId, string $address, string $reason, ?string $source = null, string $channel = 'email'): void
    {
        DB::table('comm_suppressions')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'channel'   => $channel,
            'address'   => self::normalise($address, $channel),
            'reason'    => $reason,
            'source'    => $source,
            'created_at'=> now(),
        ]);
    }
}
