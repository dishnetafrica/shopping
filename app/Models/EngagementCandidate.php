<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 1 — a single customer's slot in a day's re-engagement plan. Written by EngagementPlanner via
 * the `reengage:plan` command; read by the Customer Engagement dashboard. No message is ever sent from
 * this row in Phase 1.
 */
class EngagementCandidate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'plan_date', 'phone', 'customer_profile_id', 'name',
        'score', 'campaign_type', 'reason', 'product_id', 'product_name',
        'eligible', 'blocked_reason', 'chosen', 'next_eligible_at', 'signals',
    ];

    protected $casts = [
        'plan_date'        => 'date',
        'next_eligible_at' => 'date',
        'score'            => 'integer',
        'eligible'         => 'boolean',
        'chosen'           => 'boolean',
        'signals'          => 'array',
    ];
}
