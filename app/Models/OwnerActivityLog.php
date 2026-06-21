<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OwnerActivityLog extends Model
{
    use BelongsToTenant;

    protected $table = 'owner_activity_log';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'message', 'detected_event', 'confidence', 'approved', 'created_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'approved'   => 'boolean',
        'created_at' => 'datetime',
    ];
}
