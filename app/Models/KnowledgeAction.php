<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KnowledgeAction extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id', 'capability', 'action_type', 'target', 'params_json', 'entities_json',
        'source', 'status', 'change_request_id', 'event_id', 'applied_at',
    ];
    protected $casts = ['params_json' => 'array', 'entities_json' => 'array', 'applied_at' => 'datetime'];
}
