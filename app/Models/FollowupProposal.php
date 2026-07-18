<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowupProposal extends Model
{
    protected $fillable = ['tenant_id', 'customer_phone', 'source', 'trigger', 'trigger_reason',
        'evidence', 'draft', 'confidence', 'expected_outcome', 'status', 'skip_reason',
        'snoozed_until', 'approved_by', 'sent_message_id', 'influenced_order_id'];

    protected $casts = ['snoozed_until' => 'datetime'];
}
