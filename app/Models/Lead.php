<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_phone', 'customer_name', 'company', 'intent', 'interest', 'dedupe_key', 'lead_score',
        'message', 'notes', 'source', 'tag', 'marketing_opt_in', 'conversation_id', 'status', 'assigned_to', 'claimed_at',
        'next_followup_at', 'last_contacted_at', 'owner_user_id',
        // Sales pipeline (Internet providers; written only by App\Services\Sales\Pipeline\PipelineService)
        'stage', 'stage_changed_at', 'qualification', 'coverage_status', 'next_action', 'lost_reason', 'work_order_id',
    ];

    protected $casts = [
        'claimed_at'        => 'datetime',
        'next_followup_at'  => 'datetime',
        'last_contacted_at' => 'datetime',
        'lead_score'        => 'integer',
        'marketing_opt_in'  => 'boolean',
        'stage_changed_at'  => 'datetime',
        'qualification'     => 'array',
    ];

    public const OPEN_STATUSES = ['new', 'assigned', 'contacted', 'qualified'];
}
