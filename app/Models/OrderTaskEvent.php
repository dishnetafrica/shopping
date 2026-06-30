<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only timeline row. No updated_at — events never change. */
class OrderTaskEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'order_id', 'task_id', 'event', 'actor_user_id', 'actor_name',
        'from_stage', 'to_stage', 'detail', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
