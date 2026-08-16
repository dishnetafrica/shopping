<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\CommCampaign;
use App\Models\CommSuppression;
use App\Models\CommTemplate;
use App\Models\Tenant;
use App\Services\Comms\CampaignService;
use App\Services\Comms\TemplateRenderer;
use App\Services\Crm\BusinessStage;
use Illuminate\Support\Facades\DB;

/**
 * A TRUE dry run: the eligibility funnel + a rendered example, sending nothing.
 *
 * The funnel is computed by walking the opted-in audience through the SAME CampaignSafetyGuard the
 * real send path uses — not a parallel set of "preview rules". That is deliberate (requirement 3):
 * preview and send can never disagree about who is eligible.
 *
 * Purchase-based audiences are counted from AVAILABLE order data (order_items + items_json), which may
 * be incomplete — so `data_caveat` is always returned and the UI must show counts as "matched from
 * available order data", never as a guaranteed-complete total.
 */
final class CampaignPreview
{
    public const SAMPLE_CAP = 500;

    /** @return array<string,mixed> */
    public static function for(Tenant $tenant, CommCampaign $campaign, int $sampleCap = self::SAMPLE_CAP): array
    {
        $svc = app(CampaignService::class);
        $seg = (array) $campaign->segment;

        $totalMatching = self::totalMatching($tenant, $seg);          // segment match, any consent
        $optedIn       = (int) $svc->audienceQuery($tenant, $seg, 'whatsapp')->count();

        // Walk the opted-in audience (capped) through the real guard.
        $phones = $svc->audienceQuery($tenant, $seg, 'whatsapp')
            ->orderBy('customer_profiles.id')->limit($sampleCap)
            ->pluck('customer_profiles.phone');

        $eligible = 0; $suppressed = 0; $frequency = 0; $otherReasons = [];
        foreach ($phones as $phone) {
            $digits = CommSuppression::normalise((string) $phone, 'whatsapp');
            $reason = CampaignSafetyGuard::blockedReason($tenant, $campaign, $digits, 'preview');
            if ($reason === null) {
                $eligible++;
            } elseif (in_array($reason, [CampaignSafetyGuard::R_OPTED_OUT, CampaignSafetyGuard::R_SUPPRESSED], true)) {
                $suppressed++;
            } elseif ($reason === CampaignSafetyGuard::R_FREQUENCY) {
                $frequency++;
            } else {
                $otherReasons[$reason] = ($otherReasons[$reason] ?? 0) + 1;
            }
        }
        $sampled = $phones->count() >= $sampleCap && $optedIn > $sampleCap;

        $limit     = DailyLimiter::limit($tenant);
        $sentToday = DailyLimiter::sentToday($tenant);
        $remaining = DailyLimiter::remaining($tenant);
        $eligibleNow = min($eligible, $remaining);

        $throttle    = max(1, (int) $campaign->throttle_per_minute);
        $minutesToday = (int) ceil($eligibleNow / $throttle);
        $days         = $limit > 0 ? (int) ceil($eligible / $limit) : 1;

        return [
            'audience_name'   => self::audienceName($seg),
            'audience_def'    => self::audienceDef($seg),
            'total_matching'  => $totalMatching,
            'opted_in'        => $optedIn,
            'suppressed'      => $suppressed,
            'frequency_capped'=> $frequency,
            'other_skips'     => $otherReasons,
            'eligible'        => $eligible,
            'sampled'         => $sampled,
            'sample_cap'      => $sampleCap,
            'daily_limit'     => $limit,
            'sent_today'      => $sentToday,
            'daily_remaining' => $remaining,
            'eligible_now'    => $eligibleNow,
            'est_minutes_today' => $minutesToday,
            'est_days'        => max(1, $days),
            'example'         => self::example($tenant, $campaign),
            'data_caveat'     => 'Purchase-based audiences are matched from available order data '
                . '(order lines and order history), which may be incomplete — treat counts as a floor, not a guaranteed total.',
        ];
    }

    /** Segment match ignoring consent — the "total matching" figure, so the funnel shows the drop-off. */
    private static function totalMatching(Tenant $tenant, array $seg): int
    {
        $q = DB::table('customer_profiles')->where('tenant_id', $tenant->id)
            ->whereRaw(BusinessStage::sql())
            ->whereNotNull('phone')->whereRaw('length(phone) >= 7');
        AudienceSegments::apply($q, $tenant, $seg);

        return (int) $q->count();
    }

    private static function example(Tenant $tenant, CommCampaign $campaign): string
    {
        $renderer = app(TemplateRenderer::class);

        $text = (string) $campaign->text_body;
        if ($campaign->template_key) {
            $tpl = CommTemplate::resolve($tenant->id, $campaign->template_key, 'en', 'whatsapp');
            if ($tpl && $tpl->text_body !== null) {
                $text = (string) $tpl->text_body;
            }
        }
        if (trim($text) === '') {
            return '(no message body set)';
        }

        $vars = ['customer_name' => 'Aisha'] + OfferVars::build($tenant, $campaign->offer);

        return $renderer->renderString($text, array_merge($renderer->defaults($tenant), $vars), false);
    }

    private static function audienceName(array $seg): string
    {
        foreach (['customer_type', 'bought_category', 'not_bought_category', 'bought_product', 'segment', 'city'] as $k) {
            if (! empty($seg[$k])) {
                return AudienceSegments::TYPES[$k] ?? ucfirst(str_replace('_', ' ', $k));
            }
        }
        if (! empty($seg['ordered_within_days']))     { return AudienceSegments::TYPES['ordered_within_days']; }
        if (! empty($seg['not_ordered_within_days'])) { return AudienceSegments::TYPES['not_ordered_within_days']; }
        if (! empty($seg['ordered_between']))         { return AudienceSegments::TYPES['ordered_between']; }
        if (! empty($seg['not_ordered_between']))     { return AudienceSegments::TYPES['not_ordered_between']; }

        return AudienceSegments::TYPES['all'];
    }

    /** A short human description of the audience definition, for the preview + campaign detail. */
    private static function audienceDef(array $seg): string
    {
        if (! $seg) {
            return 'All opted-in customers';
        }
        $parts = [];
        foreach ($seg as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $label = AudienceSegments::TYPES[$k] ?? str_replace('_', ' ', $k);
            $parts[] = is_array($v) ? ($label . ' (' . implode('–', array_map('strval', $v)) . ')') : ($label . ': ' . $v);
        }

        return $parts ? implode(' · ', $parts) : 'All opted-in customers';
    }
}
