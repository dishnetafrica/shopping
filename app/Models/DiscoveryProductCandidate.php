<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An owner decision on a mined product candidate. Created when the owner approves a candidate
 * into the catalogue (links the new draft Product) or dismisses it. Its presence stops the term
 * re-appearing in the Business Brain's "Product Candidates" list.
 */
class DiscoveryProductCandidate extends Model
{
    use BelongsToTenant;

    protected $table = 'discovery_product_candidates';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'term', 'term_norm', 'decision', 'product_id', 'decided_by', 'created_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
