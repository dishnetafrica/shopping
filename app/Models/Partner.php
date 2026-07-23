<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','name','user_id','ownership_pct','active'];
    protected $casts = ['ownership_pct' => 'decimal:2', 'active' => 'boolean'];
}
