<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ShadowDiff extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'conversation_id', 'message', 'live_json', 'shadow_json',
        'diff_json', 'reasons', 'regression', 'status', 'created_at'];
    protected $casts = ['live_json' => 'array', 'shadow_json' => 'array', 'diff_json' => 'array',
        'regression' => 'boolean', 'created_at' => 'datetime'];
}
