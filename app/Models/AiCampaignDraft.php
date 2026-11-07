<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One AI-personalised campaign message awaiting human review → paced send (v4.5). */
class AiCampaignDraft extends Model
{
    use BelongsToTenant;   // P0: structural tenant scoping (panel access filters tenant_id; the
                           // AiCampaignSendCommand sweep opts out via withoutGlobalScopes).

    protected $fillable = ['tenant_id', 'campaign_id', 'phone', 'name', 'body', 'status', 'error', 'sent_at'];
    protected $casts = ['sent_at' => 'datetime'];
}
