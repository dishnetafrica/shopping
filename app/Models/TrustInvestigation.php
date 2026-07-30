<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Inventory Trust Engine v1 (Spec v1.0) — thin model over trust_investigations. */
class TrustInvestigation extends Model
{
    use BelongsToTenant;

    protected $table = 'trust_investigations';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'reason_codes' => 'array', 'applies_to' => 'array'];
}
