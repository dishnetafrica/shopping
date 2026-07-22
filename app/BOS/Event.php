<?php

namespace App\BOS;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable business fact (BOS v1.0, doc 5). Append-only by contract (ADR-002): once written it
 * can never be updated or deleted. Corrections are NEW events (a void/reverse), never edits.
 *
 * Objects are the source of truth; this is history. The append-only guard is enforced here at the
 * model layer so no code path — service, job, or console — can mutate the record.
 */
class Event extends Model
{
    use BelongsToTenant;

    protected $table = 'bos_events';
    protected $primaryKey = 'event_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'tenant_id', 'name', 'subject_type', 'subject_id',
        'actor_type', 'actor_id', 'actor_name', 'from_state', 'to_state',
        'payload', 'idempotency_key', 'occurred_at', 'recorded_at',
        // instrumentation (ADR-015)
        'correlation_id', 'causation_id', 'device', 'connectivity', 'app_version', 'latency_ms', 'context',
    ];

    protected $casts = [
        'payload' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // R22/ADR-002 — append-only. Block any update or delete of an existing event.
        static::updating(function () {
            throw new \RuntimeException('BOS events are append-only: an event can never be updated (record a corrective event instead).');
        });
        static::deleting(function () {
            throw new \RuntimeException('BOS events are append-only: an event can never be deleted.');
        });
    }
}
