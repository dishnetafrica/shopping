<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Optional saved details for a customer (keyed by WhatsApp phone). */
class CustomerProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'phone', 'name', 'contact_person', 'segment', 'alt_phone', 'email', 'address', 'city', 'country', 'credit_limit', 'lang', 'greeting', 'notes', 'ss_markup_pct', 'assigned_rep', 'follow_up', 'last_contacted_at', 'last_whatsapp_at'];

    protected $casts = ['credit_limit' => 'decimal:2', 'last_contacted_at' => 'datetime', 'last_whatsapp_at' => 'datetime'];
}
