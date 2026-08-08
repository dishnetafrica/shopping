<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v4.62 — a discovered business waiting for a human.
 *
 * Rows only ever propose values for fields that were EMPTY when the row was made, and the check is
 * made again at approval — see App\Services\Discovery\BusinessReview::applyOne(). Approving can
 * therefore never destroy something a person typed, which matters most in bulk, where nobody is
 * reading 800 rows carefully enough to catch it by eye.
 */
class BusinessSuggestion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'identity_key', 'customer_profile_id', 'name', 'phone', 'city',
        'findings', 'proposed', 'provider', 'confidence', 'status', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'findings' => 'array',
        'proposed' => 'array',
        'confidence' => 'float',
        'decided_at' => 'datetime',
    ];

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }

    /** True when approving would create a record rather than fill gaps in one. */
    public function isNew(): bool
    {
        return $this->customer_profile_id === null;
    }
}
