<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One WhatsApp message (inbound or outbound) in a customer's thread. */
class Message extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_phone', 'instance',
        'direction', 'sender', 'body', 'wa_message_id', 'status', 'meta',
    ];

    protected $casts = ['meta' => 'array'];
}
