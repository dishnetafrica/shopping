<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Per-customer bot session state (cart, step) for one tenant. */
class Conversation extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','customer_phone','instance','state','cart','last_message_at','agent_active','unread','last_inbound_at','owner_user_id'];
    protected $casts = ['state'=>'array','cart'=>'array','last_message_at'=>'datetime','last_inbound_at'=>'datetime','agent_active'=>'boolean', 'review' => 'array', 'summarized_at' => 'datetime', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        // Sales team (App\Services\Sales\SalesOwnership): a new chat with a customer who already has
        // a salesperson starts out theirs, whoever creates the row (webhook job, panel, website chat).
        // One indexed lookup per NEW conversation; existing conversations are never touched here.
        static::creating(function (Conversation $c) {
            static $ready = null;
            if ($c->owner_user_id || ! $c->tenant_id || ! $c->customer_phone) {
                return;
            }
            // Checked once per process: a failed query inside a PostgreSQL transaction would poison
            // the caller's transaction even though the error is caught here.
            $ready ??= \Illuminate\Support\Facades\Schema::hasColumn('customer_profiles', 'owner_user_id');
            if (! $ready) {
                return;
            }
            try {
                $owner = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $c->tenant_id)
                    ->where('phone', preg_replace('/[^0-9]/', '', (string) $c->customer_phone))->value('owner_user_id');
                if ($owner) {
                    $c->owner_user_id = (int) $owner;
                }
            } catch (\Throwable $e) {
                // ownership is an inbox convenience; never let it stop a conversation being created
            }
        });
    }
}
