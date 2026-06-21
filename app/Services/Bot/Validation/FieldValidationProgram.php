<?php

namespace App\Services\Bot\Validation;

use App\Models\FieldValidation;
use App\Models\Tenant;

/**
 * Field Validation — the program runner. Orchestrates real businesses through the existing
 * Discovery → Readiness → Validation pipeline and records field outcomes. Builds NO new
 * intelligence: scanning is delegated to ValidationService, comparison to ValidationComparator.
 */
class FieldValidationProgram
{
    public function __construct(protected ValidationService $validation) {}

    /** Step 1-2: enroll a connected business (history import happens before scan). */
    public function enroll(Tenant $tenant, string $businessType, ?string $name = null): FieldValidation
    {
        return FieldValidation::create([
            'tenant_id'     => $tenant->id,
            'business_name' => $name ?: (string) ($tenant->name ?? ''),
            'business_type' => $businessType,
            'status'        => 'enrolled',
            'enrolled_at'   => now(),
        ]);
    }

    /**
     * Step 3-5: import + scan + DNA + go-live, measured against the operator's pre-review ground
     * truth. Stores discovered sections so the owner review can re-score without rescanning.
     */
    public function scan(FieldValidation $record, array $operatorTruth): FieldValidation
    {
        $tenant = Tenant::find($record->tenant_id);
        if (! $tenant) return $record;

        $importedAt = $record->imported_at ?? now();
        $result = $this->validation->runTenant($tenant, $record->business_type, $operatorTruth, false);

        $record->update([
            'status'               => 'scanned',
            'imported_at'          => $importedAt,
            'scanned_at'           => now(),
            'messages_scanned'     => $result['messages_scanned'],
            'products_found'       => $result['products_found'],
            'faq_found'            => $result['faq_found'],
            'delivery_rules_found' => $result['delivery_rules_found'],
            'readiness_score'      => $result['readiness_score'],
            'actual_accuracy'      => $result['accuracy_score'],
            'detail'               => [
                'sections'       => $result['sections'] ?? [],
                'metrics'        => $result['metrics'] ?? [],
                'operator_truth' => $operatorTruth,
            ],
        ]);

        return $record->fresh();
    }

    /**
     * Step 6-7: owner reviews the findings and supplies a corrected ground truth. We re-score
     * against it (owner_approved_accuracy), derive the edits needed, and stamp time-to-go-live.
     */
    public function recordOwnerReview(FieldValidation $record, array $correctedTruth, bool $goLive = true): FieldValidation
    {
        $sections = (array) ($record->detail['sections'] ?? []);
        $metrics  = ValidationComparator::compare($sections, $correctedTruth, (int) $record->readiness_score);

        $edits   = FieldMetrics::editsFromMetrics($metrics);
        $gtSize  = FieldMetrics::groundTruthSize($metrics);
        $corrPct = FieldMetrics::correctionsPct($edits, $gtSize);

        $reviewedAt = now();
        $importedAt = $record->imported_at ?? $record->scanned_at ?? $reviewedAt;
        $minutes    = $importedAt->diffInMinutes($reviewedAt);

        $detail = (array) $record->detail;
        $detail['corrected_truth']  = $correctedTruth;
        $detail['review_metrics']   = $metrics;

        $record->update([
            'status'                  => $goLive ? 'live' : 'reviewed',
            'reviewed_at'             => $reviewedAt,
            'went_live_at'            => $goLive ? $reviewedAt : null,
            'owner_approved_accuracy' => $metrics['overall_accuracy'],
            'owner_edits_required'    => $edits,
            'owner_corrections_pct'   => $corrPct,
            'time_to_go_live_min'     => $minutes,
            'detail'                  => $detail,
        ]);

        return $record->fresh();
    }

    /** Dashboard data: per-business rows + cohort summary + verdict. */
    public function dashboard(): array
    {
        $records = FieldValidation::orderBy('business_type')->orderBy('id')->get();

        $rows = $records->map(fn (FieldValidation $r) => [
            'id'                      => $r->id,
            'business_name'           => $r->business_name,
            'business_type'           => $r->business_type,
            'status'                  => $r->status,
            'readiness_score'         => $r->readiness_score,
            'actual_accuracy'         => $r->actual_accuracy,
            'owner_approved_accuracy' => $r->owner_approved_accuracy,
            'time_to_go_live_min'     => $r->time_to_go_live_min,
            'owner_edits_required'    => $r->owner_edits_required,
            'owner_corrections_pct'   => $r->owner_corrections_pct,
        ])->all();

        $reviewed = array_values(array_filter($rows, fn ($r) => in_array($r['status'], ['reviewed', 'live'], true)));
        $summary  = FieldMetrics::summary($reviewed);
        $verdict  = FieldMetrics::verdict($summary);

        return ['rows' => $rows, 'summary' => $summary, 'verdict' => $verdict];
    }
}
