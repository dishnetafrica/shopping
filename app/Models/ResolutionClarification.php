<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ResolutionClarification extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'conversation_id', 'query', 'candidates_json', 'chosen_product_id', 'customer_reply', 'created_at'];
    protected $casts = ['candidates_json' => 'array', 'created_at' => 'datetime'];
}
