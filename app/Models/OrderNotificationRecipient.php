<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp number that receives this store's order notifications.
 * Tenant-isolated via BelongsToTenant.
 */
class OrderNotificationRecipient extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'phone', 'active'];
    protected $casts = ['active' => 'boolean'];

    /** Store the phone as digits only (format-insensitive). */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = preg_replace('/\D+/', '', (string) $value) ?: '';
    }
}
