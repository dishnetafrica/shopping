<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-campaign daily batching for the classic Campaigns screen — "send X/day, spread over the week."
 * null/0 = send straight through as before; a positive value caps the campaign to that many per day and
 * the SendCampaign job resumes at the next tenant-local midnight until the audience is exhausted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaigns') || Schema::hasColumn('campaigns', 'daily_send_cap')) {
            return;
        }
        Schema::table('campaigns', function (Blueprint $t) {
            $t->unsignedInteger('daily_send_cap')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('campaigns') && Schema::hasColumn('campaigns', 'daily_send_cap')) {
            Schema::table('campaigns', function (Blueprint $t) {
                $t->dropColumn('daily_send_cap');
            });
        }
    }
};
