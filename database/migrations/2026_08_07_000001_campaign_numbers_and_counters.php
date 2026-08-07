<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.49 — campaigns get a number, from today onwards.
 *
 * A campaign has had a name, a type, an audience and a database id, and nothing else. Send
 * "POSHY SERVIETTES – WHOLESALE" every month and you get five rows with identical names,
 * distinguishable only by a date column — no way to say "the one from August" on the phone, in a
 * report, or to each other.
 *
 * Quotations, invoices and credit notes all already run on a gapless per-year counter. This is the
 * same table shape (`invoice_counters`, `quote_counters`) so there is one convention across all
 * four numbered document types, not four.
 *
 * Existing campaigns are LEFT ALONE — `campaign_no` stays null on everything already sent, exactly
 * as asked. Numbering starts at 1 and applies to campaigns created from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_counters')) {
            Schema::create('campaign_counters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedInteger('year')->default(0);   // 0 = one running series, never resets
                $table->unsignedBigInteger('last_seq')->default(0);
                $table->timestamps();
                $table->unique(['tenant_id', 'year'], 'campaign_counters_tenant_year_unique');
            });
        }

        if (Schema::hasTable('campaigns') && ! Schema::hasColumn('campaigns', 'campaign_no')) {
            Schema::table('campaigns', function (Blueprint $table) {
                // Nullable on purpose: every campaign that already exists keeps no number rather
                // than being retro-fitted with one it was never sent under.
                $table->string('campaign_no', 32)->nullable()->after('name');
            });
            try {
                Schema::table('campaigns', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'campaign_no'], 'campaigns_tenant_no_unique');
                });
            } catch (\Throwable $e) {
                // A residual collision or an existing index — leave the table usable either way.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_counters');
        if (Schema::hasTable('campaigns') && Schema::hasColumn('campaigns', 'campaign_no')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropColumn('campaign_no');
            });
        }
    }
};
