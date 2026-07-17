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

    public static function isSuppressed(int $tenantId, string $address, string $channel = 'email'): bool
    {
        return DB::table('comm_suppressions')
            ->where('tenant_id', $tenantId)->where('channel', $channel)
            ->where('address', mb_strtolower(trim($address)))
            ->exists();
    }

    /** Idempotent insert (unique index makes duplicates a no-op). */
    public static function add(int $tenantId, string $address, string $reason, ?string $source = null, string $channel = 'email'): void
    {
        DB::table('comm_suppressions')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'channel'   => $channel,
            'address'   => mb_strtolower(trim($address)),
            'reason'    => $reason,
            'source'    => $source,
            'created_at'=> now(),
        ]);
    }
}
