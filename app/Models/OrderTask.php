<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\WorkflowConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTask extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'ref_no', 'order_id', 'seq', 'stage', 'role', 'owner_user_id', 'title', 'status',
        'sla_minutes', 'paused_minutes', 'assigned_at', 'started_at', 'due_at', 'paused_at',
        'completed_at', 'completed_by', 'exception_type', 'exception_reason',
        'requires_upload', 'attachment', 'note', 'escalated', 'checklist', 'priority', 'waiting_for',
        'accepted_at', 'reminded_at',
        'link_nonce', 'opened_at',
    ];

    protected $casts = [
        'ref_no' => 'integer',
        'seq' => 'integer', 'sla_minutes' => 'integer', 'paused_minutes' => 'integer',
        'requires_upload' => 'boolean', 'escalated' => 'boolean',
        'checklist' => 'array',
        'priority' => 'integer',
        'assigned_at' => 'datetime', 'started_at' => 'datetime', 'due_at' => 'datetime',
        'paused_at' => 'datetime', 'completed_at' => 'datetime', 'accepted_at' => 'datetime', 'reminded_at' => 'datetime', 'opened_at' => 'datetime',
    ];

    /** A task is "active" while it still needs work (the order's single live task). */
    public const ACTIVE = ['offered', 'pending', 'in_progress', 'exception', 'paused'];

    protected static function booted(): void
    {
        // Assign the next per-tenant reference number on insert. Runs AFTER BelongsToTenant's
        // creating hook (which stamps tenant_id), so tenant_id is available here. Guarded so it
        // never overwrites an explicit value and stays a no-op if the ref_no column isn't there yet
        // (e.g. before this migration has run on an older database).
        static::creating(function (OrderTask $task) {
            if (! empty($task->ref_no) || empty($task->tenant_id)) return;
            if (! \Illuminate\Support\Facades\Schema::hasColumn($task->getTable(), 'ref_no')) return;
            $max = static::withoutGlobalScopes()->where('tenant_id', $task->tenant_id)->max('ref_no');
            $task->ref_no = (int) $max + 1;
        });
    }

    /** Human-facing reference like "#7" (falls back to the system id if not yet numbered). */
    public function refLabel(): string
    {
        return '#' . ($this->ref_no ?: $this->id);
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }

    public function isActive(): bool { return in_array($this->status, self::ACTIVE, true); }

    public function isOverdue(): bool
    {
        return $this->due_at
            ? WorkflowConfig::isOverdue($this->status, $this->due_at)
            : false;
    }

    /** Checklist completion as a 0-100 percent (0 if no checklist). */
    public function progress(): int
    {
        $items = (array) ($this->checklist ?? []);
        if (! $items) return 0;
        $done = 0;
        foreach ($items as $i) if (! empty($i['done'])) $done++;
        return (int) round($done / max(1, count($items)) * 100);
    }
}
