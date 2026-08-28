<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — the daily re-engagement snapshot.
 *
 * One row per (tenant, plan_date, customer). The daily `reengage:plan` job scores EVERY past-buyer,
 * records the eligibility decision and score for all of them (so the dashboard can honestly say "N of
 * 700 are eligible"), and marks the top `chosen` = today's contact list. Phase 1 writes this table and
 * NOTHING ELSE — no message is sent. It is a plan/audit surface, safe to rebuild any day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('engagement_candidates')) {
            return;
        }
        Schema::create('engagement_candidates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->date('plan_date');
            $t->string('phone', 32);                    // digits, the join key used everywhere
            $t->unsignedBigInteger('customer_profile_id')->nullable();
            $t->string('name', 191)->nullable();

            $t->unsignedTinyInteger('score')->default(0);        // 0–100 contact score (eligible only)
            $t->string('campaign_type', 40)->nullable();          // dormant_winback | replenishment | …
            $t->string('reason', 255)->nullable();                // why selected (human, from ContactScore)

            $t->unsignedBigInteger('product_id')->nullable();     // recommended product (CrossSell)
            $t->string('product_name', 191)->nullable();

            $t->boolean('eligible')->default(false);
            $t->string('blocked_reason', 40)->nullable();         // not_opted_in | opted_out | cooldown | …
            $t->boolean('chosen')->default(false)->index();       // in today's top-N contact list
            $t->date('next_eligible_at')->nullable();             // when a cooled-down customer re-opens

            $t->json('signals')->nullable();                      // the raw gathered signals, for audit
            $t->timestamps();

            $t->unique(['tenant_id', 'plan_date', 'phone'], 'engc_tenant_date_phone_uq');
            $t->index(['tenant_id', 'plan_date', 'chosen'], 'engc_tenant_date_chosen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_candidates');
    }
};
