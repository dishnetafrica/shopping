<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderDraft extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'conversation_id', 'payload_json', 'status'];
    protected $casts = ['payload_json' => 'array'];

    public function events() { return $this->hasMany(OrderDraftEvent::class, 'draft_id'); }
}
