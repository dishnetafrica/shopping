<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Platform Validation run — discovered-vs-actual measurement for a real (or fixture) business.
 * Not tenant-scoped: fixtures have no tenant, and validation is an operator/QA concern.
 */
class ValidationRun extends Model
{
    protected $fillable = [
        'tenant_id', 'business_type', 'messages_scanned', 'products_found', 'faq_found',
        'delivery_rules_found', 'readiness_score', 'accuracy_score', 'scan_ms', 'detail', 'created_at',
    ];

    protected $casts = [
        'messages_scanned'     => 'integer',
        'products_found'       => 'integer',
        'faq_found'            => 'integer',
        'delivery_rules_found' => 'integer',
        'readiness_score'      => 'integer',
        'accuracy_score'       => 'integer',
        'scan_ms'              => 'integer',
        'detail'               => 'array',
        'created_at'           => 'datetime',
    ];

    public $timestamps = false;
}
