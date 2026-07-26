<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A captured Project Advisor engagement: the discovery answers plus the deterministic sizing snapshot.
 * Tenant-scoped. `answers` and `sizing` are jsonb blobs (schema-flexible across industries/profiles).
 */
class AdvisorProject extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'created_by', 'profile', 'name', 'status',
        'answers', 'sizing', 'proposal', 'quotation_id',
    ];

    protected $casts = [
        'answers'  => 'array',
        'sizing'   => 'array',
        'proposal' => 'array',
    ];
}
