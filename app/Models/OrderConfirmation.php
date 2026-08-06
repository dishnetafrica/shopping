<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v4.43 — a frozen copy of what the customer was told, and whether they agreed to it.
 *
 * `snapshot` is the promise; `fingerprint` is how we know the live order still matches it.
 * Nothing here is ever edited after `sent_at` except the confirmation stamps — an audit
 * record you can rewrite is not an audit record (same reason attachments are detached
 * rather than deleted, and order_costs are voided rather than removed).
 */
class OrderConfirmation extends Model
{
    protected $fillable = [
        'tenant_id', 'order_id', 'order_no', 'token', 'snapshot', 'fingerprint',
        'message_text', 'sent_at', 'send_error', 'trigger',
        'confirmed_at', 'confirmed_via', 'confirmed_ip', 'confirmed_by', 'confirmed_note',
        'superseded_at',
    ];

    protected $casts = [
        'snapshot'      => 'array',
        'sent_at'       => 'datetime',
        'confirmed_at'  => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }
}
