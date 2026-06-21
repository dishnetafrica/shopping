<?php

namespace App\Services\Bot\Validation;

use App\Models\FieldValidation;
use Illuminate\Support\Carbon;

/**
 * Platform Validation Campaign — framework facade. Reads field_validations and produces the
 * business-type leaderboard, the five campaign answers, and the monthly report. No new analysis —
 * it delegates all math to CampaignMetrics.
 */
class CampaignService
{
    /** Reviewed/live businesses as analytics rows, optionally within a date window. */
    public function rows(?Carbon $from = null, ?Carbon $to = null): array
    {
        $q = FieldValidation::whereIn('status', ['reviewed', 'live']);
        if ($from) $q->where('reviewed_at', '>=', $from);
        if ($to)   $q->where('reviewed_at', '<=', $to);

        return $q->get()->map(fn (FieldValidation $r) => [
            'id'                      => $r->id,
            'business_name'           => $r->business_name,
            'business_type'           => $r->business_type,
            'owner_approved_accuracy' => $r->owner_approved_accuracy,
            'actual_accuracy'         => $r->actual_accuracy,
            'owner_corrections_pct'   => $r->owner_corrections_pct,
            'owner_edits_required'    => $r->owner_edits_required,
            'time_to_go_live_min'     => $r->time_to_go_live_min,
            'readiness_score'         => $r->readiness_score,
            'messages_scanned'        => $r->messages_scanned,
            'products_found'          => $r->products_found,
            'faq_found'               => $r->faq_found,
            'delivery_rules_found'    => $r->delivery_rules_found,
            'products_accuracy'       => $r->products_accuracy,
            'faq_accuracy'            => $r->faq_accuracy,
            'delivery_accuracy'       => $r->delivery_accuracy,
            'offer_accuracy'          => $r->offer_accuracy,
            'language_accuracy'       => $r->language_accuracy,
        ])->all();
    }

    public function leaderboard(): array
    {
        return CampaignMetrics::leaderboard($this->rows());
    }

    public function questions(): array
    {
        return CampaignMetrics::questions($this->rows());
    }

    /** Monthly report for a given YYYY-MM (defaults to current month). */
    public function monthlyReport(?string $month = null): array
    {
        $month = $month ?: date('Y-m');
        $from  = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
        $to    = (clone $from)->endOfMonth();

        return CampaignMetrics::monthlyReport($this->rows($from, $to), $month);
    }
}
