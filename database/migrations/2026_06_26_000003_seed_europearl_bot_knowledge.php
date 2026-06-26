<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time, idempotent starter for the EuroPearl / Krishna Wellness bot:
 * sets a friendly AI persona and a FACTUAL knowledge starter — but ONLY for settings
 * that are currently empty, so it never overwrites anything the owner has written.
 *
 * Content is deliberately limited to facts the business already states (2-ply, 100%
 * virgin pulp, cartons + retail packs, Uganda manufacturer). Discount tiers, lead
 * times, delivery areas and payment terms are intentionally LEFT OUT — the owner fills
 * those in the panel so the bot never quotes a figure that isn't real.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('tenants')
            ->where(function ($q) {
                $q->where('name', 'like', '%Krishna%')
                  ->orWhere('name', 'like', '%EuroPearl%')
                  ->orWhere('name', 'like', '%Europearl%');
            })
            ->get();

        foreach ($tenants as $t) {
            $s = json_decode($t->settings ?? '{}', true);
            if (! is_array($s)) $s = [];

            $name    = (string) ($t->name ?: 'EuroPearl');
            $changed = false;

            if (trim((string) ($s['ai_persona'] ?? '')) === '') {
                $s['ai_persona'] = "You are the friendly, professional WhatsApp sales assistant for {$name} — a Uganda-based manufacturer of paper and tissue products (EuroPearl and related brands). Be warm, concise and helpful. Answer product questions clearly, guide the customer toward placing an order, and always capture the quantity and delivery area. For bulk / wholesale buyers, offer to prepare a quotation rather than guessing a price.";
                $changed = true;
            }

            if (trim((string) ($s['brand_knowledge'] ?? '')) === '') {
                $s['brand_knowledge'] = implode("\n", [
                    "- We manufacture paper and tissue products in Uganda under the EuroPearl brand (toilet paper, kitchen towels, napkins and more).",
                    "- Our toilet paper is 2-ply, made from 100% virgin pulp for softness and strength. Popular formats include 300-sheet rolls.",
                    "- Products are sold by the carton (wholesale) and in smaller retail packs (e.g. a 4-roll retail pack).",
                    "- For bulk or wholesale quantities we offer special pricing — share the items and how many, and I'll prepare a quotation with the exact rates.",
                    "- To arrange delivery, share your town/area and the quantity, and I'll confirm the options.",
                ]);
                $changed = true;
            }

            if ($changed) {
                DB::table('tenants')->where('id', $t->id)->update([
                    'settings'   => json_encode($s),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No-op: starter text is owner-editable in the panel; we don't auto-remove it.
    }
};
