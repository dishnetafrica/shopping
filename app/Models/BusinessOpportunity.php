<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A qualified (or qualifying) B2B business opportunity captured by the bot.
 * See migration for the full rationale. The bot never negotiates price here — it
 * understands, qualifies, scores, sizes, and hands off to a human.
 */
class BusinessOpportunity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'conversation_id', 'lead_id', 'customer_phone', 'customer_name',
        'type', 'stage', 'status', 'lead_score', 'score_breakdown', 'size_band',
        'profile', 'missing', 'assigned_to', 'next_followup_at', 'last_scored_at', 'handed_off_at',
    ];

    protected $casts = [
        'score_breakdown'  => 'array',
        'profile'          => 'array',
        'missing'          => 'array',
        'lead_score'       => 'integer',
        'next_followup_at' => 'datetime',
        'last_scored_at'   => 'datetime',
        'handed_off_at'    => 'datetime',
    ];

    /** Opportunity types — each tenant enables the subset it actually supports. */
    public const TYPES = [
        'distributor', 'dealer', 'wholesale', 'export', 'corporate',
        'government', 'ngo', 'partnership', 'franchise', 'tender',
    ];

    /** Pipeline stages. 'handoff' = passed to a human; the bot stops negotiating. */
    public const STAGES = ['understand', 'qualify', 'value', 'handoff', 'closed'];

    /** Still being worked by the bot/sales (not closed). */
    public const OPEN_STATUSES = ['qualifying', 'qualified', 'assigned'];

    public function tasks(): HasMany       { return $this->hasMany(OpportunityTask::class, 'opportunity_id'); }
    public function lead(): BelongsTo       { return $this->belongsTo(Lead::class); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }

    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }
}
