<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KnowledgeEvent extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'source', 'sender_ref', 'message', 'intent', 'capability', 'status'];

    public function facts() { return $this->hasMany(KnowledgeFact::class, 'event_id'); }
    public function actions() { return $this->hasMany(KnowledgeAction::class, 'event_id'); }
}
