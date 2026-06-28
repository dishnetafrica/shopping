<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderDraftEvent extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'conversation_id', 'draft_id', 'field', 'value_json', 'created_at'];
    protected $casts = ['value_json' => 'array', 'created_at' => 'datetime'];
}
