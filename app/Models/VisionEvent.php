<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Inventory Trust Engine v1 (Spec v1.0) — thin model over vision_events. */
class VisionEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'vision_events';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'reason_codes' => 'array', 'applies_to' => 'array'];
}
