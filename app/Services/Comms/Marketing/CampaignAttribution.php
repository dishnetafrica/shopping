<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\CommCampaign;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * CORRELATION-ONLY order attribution — never causal.
 *
 * ShopBot has no reliable link between a campaign message and an order today (no per-campaign code,
 * link, or reply token). So this reports only a correlation: orders placed by customers we messaged,
 * within a window after the campaign started. It is explicitly labelled as correlation and MUST NOT be
 * presented as "this campaign generated N orders". Building a causal number here would be a lie; a
 * reliable mechanism (per-campaign reply keyword / trackable link) is a documented Phase-2/3 gap.
 *
 * Phone matching is approximate (digit lists), so this is a floor/indicator, not an exact figure.
 */
final class CampaignAttribution
{
    public const DEFAULT_WINDOW_DAYS = 7;

    /** @return array<string,mixed> */
    public static function for(Tenant $tenant, CommCampaign $campaign, int $windowDays = self::DEFAULT_WINDOW_DAYS): array
    {
        $start = $campaign->started_at ?: $campaign->created_at;
        $end   = (clone $start)->addDays(max(1, $windowDays));

        // Numbers we actually sent this campaign to.
        $phones = DB::table('comm_messages')
            ->where('tenant_id', $tenant->id)
            ->where('related_type', 'campaign')->where('related_id', $campaign->id)
            ->where('channel', 'whatsapp')->where('status', 'sent')
            ->pluck('to_email')->map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p))
            ->filter()->unique()->values()->all();

        if (! $phones) {
            return self::empty($windowDays);
        }

        $orders = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_phone', $phones)
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
            ->selectRaw('count(*) as n, coalesce(sum(total), 0) as revenue')
            ->first();

        return [
            'basis'         => 'correlation',
            'window_days'   => $windowDays,
            'orders'        => (int) ($orders->n ?? 0),
            'revenue'       => (float) ($orders->revenue ?? 0),
            'recipients'    => count($phones),
            'caveat'        => 'Correlation only — orders placed by messaged customers within '
                . $windowDays . ' days of the campaign. This does NOT prove the campaign caused the order; '
                . 'reliable per-campaign attribution is not yet available.',
        ];
    }

    private static function empty(int $windowDays): array
    {
        return ['basis' => 'correlation', 'window_days' => $windowDays, 'orders' => 0,
            'revenue' => 0.0, 'recipients' => 0,
            'caveat' => 'Correlation only — nothing sent yet, so no orders to correlate.'];
    }
}
