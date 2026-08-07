<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** v4.59 — one run of one discovery provider, and what came of it. */
class DiscoveryRun extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'provider', 'status', 'query', 'found', 'usable', 'duplicates', 'note', 'user_id'];

    protected $casts = ['query' => 'array', 'found' => 'integer', 'usable' => 'integer', 'duplicates' => 'integer'];
}
