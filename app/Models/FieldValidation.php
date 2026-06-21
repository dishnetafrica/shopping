<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One enrolled business in the Field Validation program. Tracks it through the 7-step workflow
 * (connect → import → scan → DNA → go-live → owner review → measure) and stores the field metrics.
 */
class FieldValidation extends Model
{
    protected $fillable = [
        'tenant_id', 'business_name', 'business_type', 'status',
        'messages_scanned', 'products_found', 'faq_found', 'delivery_rules_found',
        'readiness_score', 'actual_accuracy', 'owner_approved_accuracy',
        'owner_edits_required', 'owner_corrections_pct', 'time_to_go_live_min',
        'detail', 'enrolled_at', 'imported_at', 'scanned_at', 'reviewed_at', 'went_live_at',
    ];

    protected $casts = [
        'messages_scanned'        => 'integer',
        'products_found'          => 'integer',
        'faq_found'               => 'integer',
        'delivery_rules_found'    => 'integer',
        'readiness_score'         => 'integer',
        'actual_accuracy'         => 'integer',
        'owner_approved_accuracy' => 'integer',
        'owner_edits_required'    => 'integer',
        'owner_corrections_pct'   => 'integer',
        'time_to_go_live_min'     => 'integer',
        'detail'                  => 'array',
        'enrolled_at'             => 'datetime',
        'imported_at'             => 'datetime',
        'scanned_at'              => 'datetime',
        'reviewed_at'             => 'datetime',
        'went_live_at'            => 'datetime',
    ];
}
