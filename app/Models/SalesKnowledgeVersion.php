<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a Sales Brain entry. Written only through App\Services\Sales\Brain\SalesBrain, which
 * enforces draft → approved → published and keeps approved content unchanged.
 */
class SalesKnowledgeVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'entry_id', 'version', 'status', 'body', 'data', 'source', 'open_questions', 'resolved_questions',
        'change_note', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'published_by', 'published_at',
        'retired_by', 'retired_at', 'rejected_by', 'rejected_at', 'reject_reason',
    ];

    protected $casts = [
        'data'               => 'array',
        'open_questions'     => 'array',
        'resolved_questions' => 'array',
        'approved_at'        => 'datetime',
        'published_at'       => 'datetime',
        'retired_at'         => 'datetime',
        'rejected_at'        => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SalesKnowledgeEntry::class, 'entry_id');
    }
}
