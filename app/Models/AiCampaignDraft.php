<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One AI-personalised campaign message awaiting human review → paced send (v4.5). */
class AiCampaignDraft extends Model
{
    protected $fillable = ['tenant_id', 'campaign_id', 'phone', 'name', 'body', 'status', 'error', 'sent_at'];
    protected $casts = ['sent_at' => 'datetime'];
}
