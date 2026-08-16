<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant daily WhatsApp marketing cap.
 *
 * Conservative and CONFIGURABLE (tenant setting `marketing_daily_limit`, default 200). Nothing in the
 * code ever raises it — an increase is only ever an explicit admin configuration change. No number
 * here is claimed to be a WhatsApp-guaranteed-safe rate; it is an internal safety control.
 *
 * "Sent today" is counted from the send ledger itself — comm_messages with status='sent' — so a
 * provider-rejected send (which the dispatcher now correctly marks failed, not sent) never inflates
 * the counter and never wrongly throttles the day. Only genuinely-sent marketing messages count;
 * transactional notifications (related_type != 'campaign') are never capped.
 */
final class DailyLimiter
{
    public const DEFAULT_LIMIT = 200;

    public static function limit(Tenant $tenant): int
    {
        return max(0, (int) $tenant->setting('marketing_daily_limit', self::DEFAULT_LIMIT));
    }

    /** Messages that genuinely LEFT today — the hard cap metric (checked immediately before each send). */
    public static function sentToday(Tenant $tenant): int
    {
        return (int) DB::table('comm_messages')
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'whatsapp')
            ->where('related_type', 'campaign')
            ->where('status', 'sent')
            ->where('sent_at', '>=', self::startOfDay($tenant))
            ->count();
    }

    /** Sent today PLUS still-in-flight rows queued today — used to pace enqueue so pending can't pile
     *  up past the cap while the dispatcher is still working through the queue. */
    public static function committedToday(Tenant $tenant): int
    {
        $start = self::startOfDay($tenant);

        $sent = (int) DB::table('comm_messages')
            ->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')->where('related_type', 'campaign')
            ->where('status', 'sent')->where('sent_at', '>=', $start)->count();

        $inFlight = (int) DB::table('comm_messages')
            ->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')->where('related_type', 'campaign')
            ->whereIn('status', ['pending', 'sending', 'retry'])->where('created_at', '>=', $start)->count();

        return $sent + $inFlight;
    }

    /** Budget available to ENQUEUE now (accounts for in-flight). */
    public static function remaining(Tenant $tenant): int
    {
        return max(0, self::limit($tenant) - self::committedToday($tenant));
    }

    /** The hard SEND-time gate: are we still under the cap on messages actually sent today? */
    public static function underSendCap(Tenant $tenant): bool
    {
        return self::sentToday($tenant) < self::limit($tenant);
    }

    /** Start of the tenant's local day, as a UTC-comparable Carbon. */
    private static function startOfDay(Tenant $tenant): Carbon
    {
        $tz = (string) ($tenant->setting('timezone', config('app.timezone')) ?: 'UTC');
        try {
            return Carbon::now($tz)->startOfDay();
        } catch (\Throwable $e) {
            return Carbon::now()->startOfDay();
        }
    }
}
