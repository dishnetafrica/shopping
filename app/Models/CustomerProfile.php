<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Optional saved details for a customer (keyed by WhatsApp phone). */
class CustomerProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'phone', 'name', 'contact_person', 'segment', 'alt_phone', 'email', 'address', 'city', 'country', 'credit_limit', 'lang', 'greeting', 'notes', 'ss_markup_pct', 'dealer_tier', 'dealer_discount_pct', 'dealer_since', 'dealer_notes', 'assigned_rep', 'follow_up', 'last_contacted_at', 'last_whatsapp_at', 'facts', 'ai_summary', 'prefs', 'stage', 'stage_changed_at', 'health', 'health_prev', 'health_flags', 'last_summarized_at', 'website', 'latitude', 'longitude', 'source', 'discovered_at', 'size', 'external_ids', 'biz_facts', 'marketing_opted_in_at', 'marketing_opt_in_source', 'marketing_opted_out_at', 'marketing_opt_out_source'];

    protected $casts = ['credit_limit' => 'decimal:2', 'last_contacted_at' => 'datetime', 'last_whatsapp_at' => 'datetime', 'facts' => 'array', 'prefs' => 'array', 'health_flags' => 'array', 'stage_changed_at' => 'datetime', 'last_summarized_at' => 'datetime', 'discovered_at' => 'datetime', 'external_ids' => 'array', 'biz_facts' => 'array', 'marketing_opted_in_at' => 'datetime', 'marketing_opted_out_at' => 'datetime'];

    /** Explicitly consented to marketing and not since opted out. Prior inbound contact is NOT consent. */
    public function marketingEligible(): bool
    {
        return $this->marketing_opted_in_at !== null && $this->marketing_opted_out_at === null;
    }

    /**
     * v4.60 — real customers only, which is what every count on the system means by "customers".
     *
     * NOT a global scope, on purpose. 37 places in this codebase look a profile up by phone and 13
     * by id, and a discovered business must still be findable by all of them — the review queue and
     * the promotion path are made of exactly those lookups. A global scope would also be bypassed
     * by every `withoutGlobalScopes()` call, which is most of the ones that enumerate. Excluding
     * has to be a decision each query makes out loud.
     */
    public function scopeCustomersOnly($q)
    {
        return \App\Services\Crm\BusinessStage::scope($q);
    }

    public function isDiscovered(): bool
    {
        return \App\Services\Crm\BusinessStage::isDiscovered($this->stage);
    }
}
