<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['tenant_id','entity_type','entity_id','action','actor','actor_id',
        'old_values','new_values','reason','ip_address','user_agent','request_id','created_at'];
    // JSONB columns cast to array — Eloquent handles encode/decode, no manual json_decode.
    protected $casts = ['old_values'=>'array','new_values'=>'array','created_at'=>'datetime'];
}
