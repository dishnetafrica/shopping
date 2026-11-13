<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry on a travel enquiry's timeline (Travel Vertical · M1). APPEND-ONLY: an event is a
 * record of something that happened, so it can be created but never changed or removed. Tenant-scoped.
 */
class TravelEnquiryEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'enquiry_id', 'type', 'actor', 'actor_user_id', 'meta', 'created_at'];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Returning false cancels the write. History is not editable.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
