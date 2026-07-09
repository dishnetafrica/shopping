<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['tenant_id','entity_type','entity_id','action','actor','actor_id','old_values','new_values','reason','created_at'];
    protected $casts = ['old_values'=>'array','new_values'=>'array','created_at'=>'datetime'];
}
