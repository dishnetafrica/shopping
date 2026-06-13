<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Optional saved details for a customer (keyed by WhatsApp phone). */
class CustomerProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'phone', 'name', 'alt_phone', 'email', 'address', 'lang', 'greeting', 'notes'];
}
