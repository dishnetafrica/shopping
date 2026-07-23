<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

/** One browser/device that asked to be buzzed. Endpoint is globally unique. */
class PushSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'endpoint', 'p256dh', 'auth'];
}
