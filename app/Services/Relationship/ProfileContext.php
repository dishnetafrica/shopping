<?php

namespace App\Services\Relationship;

use App\Models\CustomerProfile;
use App\Models\Tenant;

/**
 * V4.0 — the read side of the Memory Loop: a compact CUSTOMER PROFILE block for
 * AI context (AiBrain now; SalesCadence drafting in V4.1). Empty string when the
 * flag is off or nothing is known — callers just concatenate, zero risk.
 */
class ProfileContext
{
    public static function block(Tenant $tenant, string $phone): string
    {
        try {
            if (! (bool) $tenant->setting('relationship_memory', false)) return '';
            $p = CustomerProfile::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('phone', preg_replace('/[^0-9]/', '', $phone))
                ->first();
            if (! $p) return '';

            $bits = [];
            if ($p->name)  $bits[] = 'Name: '.$p->name;
            if ($p->segment) $bits[] = 'Segment: '.$p->segment;
            if ($p->stage) $bits[] = 'Relationship stage: '.$p->stage;
            if ($p->lang)  $bits[] = 'Preferred language: '.$p->lang;
            foreach ((array) ($p->facts ?? []) as $k => $v) {
                if (is_array($v)) $v = implode(', ', array_map('strval', $v));
                $bits[] = str_replace('_', ' ', $k).': '.mb_substr((string) $v, 0, 120);
                if (count($bits) >= 14) break;
            }
            $playbook = [
                'NEW' => 'First impressions: helpful and unhurried; learn what they need.',
                'INTERESTED' => 'Give specifics and a clear next step; offer a quotation.',
                'QUOTED' => 'Reference the open quotation; remove obstacles, do not re-pitch.',
                'NEGOTIATING' => 'Stay on the open discussion; no new discounts without a human.',
                'WAITING_BUDGET' => 'Zero pressure; keep the door open warmly.',
                'PURCHASED' => 'Aftercare tone: delivery, satisfaction, small next suggestion.',
                'REPEAT' => 'Familiar and efficient; they know us — skip the basics.',
                'VIP' => 'Gratitude and priority; flag anything unusual to a human early.',
                'DORMANT' => 'Warm and light; no guilt, no pressure, one easy re-entry.',
                'LOST' => 'Courteous and minimal; do not sell.',
            ][strtoupper((string) $p->stage)] ?? null;
            if ($playbook) $bits[] = 'Playbook for this stage: '.$playbook;
            if ($p->ai_summary) $bits[] = "Relationship summary:\n".$p->ai_summary;
            if (! $bits) return '';

            return "\n\nCUSTOMER PROFILE (from past interactions — use naturally, never recite):\n".implode("\n", $bits);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
