<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Inventory Trust Engine v1 (Spec v1.0) — thin model over trust_evidence_items. */
class TrustEvidenceItem extends Model
{
    use BelongsToTenant;

    protected $table = 'trust_evidence_items';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'reason_codes' => 'array', 'applies_to' => 'array'];
}
