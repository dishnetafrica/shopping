<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Per-customer bot session state (cart, step) for one tenant. */
class Conversation extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','customer_phone','instance','state','cart','last_message_at','agent_active','unread','last_inbound_at'];
    protected $casts = ['state'=>'array','cart'=>'array','last_message_at'=>'datetime','last_inbound_at'=>'datetime','agent_active'=>'boolean'];
}
