<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ActivityFeedItem extends Model
{
    use BelongsToTenant;

    protected $table = 'activity_feed';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'source', 'event_type', 'confidence', 'raw_content', 'payload', 'created_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];
}
