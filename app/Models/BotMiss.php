<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotMiss extends Model
{
    protected $table = 'bot_misses';
    protected $fillable = ['tenant_id','term','count','sample','resolved','last_seen_at'];
    protected $casts = ['resolved' => 'boolean', 'last_seen_at' => 'datetime'];
}
