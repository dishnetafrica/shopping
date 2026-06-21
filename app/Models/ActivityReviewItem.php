<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ActivityReviewItem extends Model
{
    use BelongsToTenant;

    protected $table = 'activity_review_queue';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'feed_item_id', 'status', 'approved_by', 'approved_at', 'created_at',
    ];

    protected $casts = [
        'feed_item_id' => 'integer',
        'approved_at'  => 'datetime',
        'created_at'   => 'datetime',
    ];
}
