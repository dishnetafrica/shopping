<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = ['tenant_id','name','user_id','ownership_pct','active'];
    protected $casts = ['ownership_pct' => 'decimal:2', 'active' => 'boolean'];
}
