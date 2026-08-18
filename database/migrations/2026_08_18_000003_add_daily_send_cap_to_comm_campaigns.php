<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-campaign daily batching — "send X per day, spread over the week."
 *
 * A conservative way to reach a large audience without a single-day blast (the thing that gets an
 * unofficial WhatsApp number restricted). null = no per-campaign limit (only the tenant-wide daily cap
 * applies, as before). A positive value caps THIS campaign to that many sends per day; the cursor
 * resumes the next day until the audience is exhausted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comm_campaigns') || Schema::hasColumn('comm_campaigns', 'daily_send_cap')) {
            return;
        }
        Schema::table('comm_campaigns', function (Blueprint $t) {
            $t->unsignedInteger('daily_send_cap')->nullable()->after('throttle_per_minute');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('comm_campaigns') && Schema::hasColumn('comm_campaigns', 'daily_send_cap')) {
            Schema::table('comm_campaigns', function (Blueprint $t) {
                $t->dropColumn('daily_send_cap');
            });
        }
    }
};
