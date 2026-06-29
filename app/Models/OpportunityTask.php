<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A concrete sales next-action tied to an opportunity. Drives Today's Priorities. */
class OpportunityTask extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'opportunity_id', 'title', 'reason', 'assigned_to',
        'priority', 'status', 'due_at', 'done_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'due_at'   => 'datetime',
        'done_at'  => 'datetime',
    ];

    public function opportunity(): BelongsTo { return $this->belongsTo(BusinessOpportunity::class, 'opportunity_id'); }
}
