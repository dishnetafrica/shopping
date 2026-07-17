<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-owned email transport account (SMTP / Mailgun / SendGrid / Postmark /
 * Brevo). Failover walks active providers ordered by `priority` ascending.
 * `config` (host, port, api keys, passwords…) is encrypted at rest via the cast.
 */
class CommProvider extends Model
{
    use BelongsToTenant;

    public const DRIVERS = ['smtp', 'mailgun', 'sendgrid', 'postmark', 'brevo'];

    protected $fillable = [
        'tenant_id', 'channel', 'name', 'driver', 'config', 'priority', 'is_active',
        'daily_limit', 'sent_today', 'sent_date', 'last_error', 'last_used_at',
    ];

    protected $casts = [
        'config'       => 'encrypted:array',
        'is_active'    => 'boolean',
        'priority'     => 'integer',
        'daily_limit'  => 'integer',
        'sent_today'   => 'integer',
        'sent_date'    => 'date',
        'last_used_at' => 'datetime',
    ];

    /** Roll the daily counter window if the calendar day changed. */
    public function resetDailyWindowIfNeeded(): void
    {
        $today = now()->toDateString();
        if (! $this->sent_date || $this->sent_date->toDateString() !== $today) {
            $this->sent_date  = $today;
            $this->sent_today = 0;
            $this->save();
        }
    }

    public function hasDailyBudget(): bool
    {
        if ($this->daily_limit === null || $this->daily_limit === 0) return true;
        return $this->sent_today < $this->daily_limit;
    }

    public function recordSend(): void
    {
        $this->sent_today  = $this->sent_today + 1;
        $this->last_used_at = now();
        $this->last_error   = null;
        $this->save();
    }

    /** Config with secrets blanked — safe to return to the panel UI. */
    public function safeConfig(): array
    {
        $cfg = $this->config ?? [];
        foreach (['password', 'api_key', 'server_token'] as $k) {
            if (! empty($cfg[$k])) $cfg[$k] = '••••••';
        }
        return $cfg;
    }
}
