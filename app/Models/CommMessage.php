<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One outbound message = one row, forever. This table is BOTH the queue
 * (status machine below) and the UCRM-style email history/audit log.
 *
 *   pending → sending → sent
 *                    ↘  retry (backoff, next_attempt_at) → sending …
 *                    ↘  failed (attempts exhausted)
 *   pending → cancelled (operator action / suppression hit)
 */
class CommMessage extends Model
{
    use BelongsToTenant;

    public const ST_PENDING   = 'pending';
    public const ST_SENDING   = 'sending';
    public const ST_SENT      = 'sent';
    public const ST_FAILED    = 'failed';
    public const ST_RETRY     = 'retry';
    public const ST_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'channel', 'token', 'to_email', 'to_name', 'cc', 'bcc', 'reply_to',
        'subject', 'html_body', 'text_body', 'template_key', 'vars', 'attachments',
        'status', 'provider_id', 'provider_message_id', 'attempts', 'max_attempts',
        'scheduled_at', 'next_attempt_at', 'sent_at', 'delivered_at', 'opened_at',
        'opens', 'clicks', 'bounced_at', 'complained_at', 'unsubscribed_at',
        'last_error', 'related_type', 'related_id', 'meta', 'created_by',
    ];

    protected $casts = [
        'cc' => 'array', 'bcc' => 'array', 'vars' => 'array',
        'attachments' => 'array', 'meta' => 'array',
        'scheduled_at' => 'datetime', 'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'opened_at' => 'datetime',
        'bounced_at' => 'datetime', 'complained_at' => 'datetime', 'unsubscribed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->token))  $m->token = Str::lower(Str::random(26));
            if (empty($m->status)) $m->status = self::ST_PENDING;
        });
    }

    public function provider()
    {
        return $this->belongsTo(CommProvider::class, 'provider_id')->withoutGlobalScopes();
    }

    public function isDue(): bool
    {
        if (! in_array($this->status, [self::ST_PENDING, self::ST_RETRY], true)) return false;
        if ($this->scheduled_at && $this->scheduled_at->isFuture()) return false;
        if ($this->next_attempt_at && $this->next_attempt_at->isFuture()) return false;
        return true;
    }
}
