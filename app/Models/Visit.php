<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** v4.63 — a rep went somewhere, and this is what happened. ADR-016 decision 5. */
class Visit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_profile_id', 'user_id', 'user_name',
        'checked_in_at', 'checked_out_at', 'latitude', 'longitude',
        'outcome', 'notes', 'photos', 'next_action', 'next_action_at', 'next_action_done',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime',
        'next_action_at' => 'datetime', 'next_action_done' => 'boolean',
        'photos' => 'array', 'latitude' => 'float', 'longitude' => 'float',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VisitItem::class);
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
