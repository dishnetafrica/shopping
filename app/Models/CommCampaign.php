<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A bulk send over a customer segment.
 *
 *   draft → scheduled → sending → done
 *                     ↘ paused ↔ sending (resume)
 *   any (pre-done)    → cancelled
 *
 * Sends are ordinary comm_messages rows tagged related_type='campaign' /
 * related_id=<id>; this row only owns the definition, the schedule, the
 * cursor and the counters.
 */
class CommCampaign extends Model
{
    use BelongsToTenant;

    public const ST_DRAFT     = 'draft';
    public const ST_SCHEDULED = 'scheduled';
    public const ST_SENDING   = 'sending';
    public const ST_PAUSED    = 'paused';
    public const ST_DONE      = 'done';
    public const ST_CANCELLED = 'cancelled';

    public const CHANNELS = ['email', 'whatsapp', 'both'];

    protected $fillable = [
        'tenant_id', 'name', 'channel', 'template_key', 'subject', 'html_body', 'text_body',
        'segment', 'offer', 'status', 'scheduled_at', 'started_at', 'finished_at',
        'total_estimated', 'enqueued_count', 'throttle_per_minute', 'daily_send_cap', 'last_enqueued_id', 'created_by',
    ];

    protected $casts = [
        'segment'      => 'array',
        'offer'        => 'array',
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function isEditable(): bool
    {
        return in_array($this->status, [self::ST_DRAFT, self::ST_SCHEDULED], true);
    }

    /** Channels this campaign actually sends on. @return string[] */
    public function channels(): array
    {
        return $this->channel === 'both' ? ['email', 'whatsapp'] : [$this->channel];
    }
}
