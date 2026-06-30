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
        'tenant_id', 'order_id', 'seq', 'stage', 'role', 'owner_user_id', 'title', 'status',
        'sla_minutes', 'paused_minutes', 'assigned_at', 'started_at', 'due_at', 'paused_at',
        'completed_at', 'completed_by', 'exception_type', 'exception_reason',
        'requires_upload', 'attachment', 'note', 'escalated', 'checklist', 'priority', 'waiting_for',
    ];

    protected $casts = [
        'seq' => 'integer', 'sla_minutes' => 'integer', 'paused_minutes' => 'integer',
        'requires_upload' => 'boolean', 'escalated' => 'boolean',
        'checklist' => 'array',
        'priority' => 'integer',
        'assigned_at' => 'datetime', 'started_at' => 'datetime', 'due_at' => 'datetime',
        'paused_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    /** A task is "active" while it still needs work (the order's single live task). */
    public const ACTIVE = ['pending', 'in_progress', 'exception', 'paused'];

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
