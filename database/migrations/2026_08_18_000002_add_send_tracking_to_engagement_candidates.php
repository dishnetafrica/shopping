<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2b — per-candidate send tracking, so the paced sender never double-sends a customer in a day
 * and the dashboard can show what actually went out. Additive columns on the Phase-1 snapshot table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('engagement_candidates')) {
            return;
        }
        Schema::table('engagement_candidates', function (Blueprint $t) {
            if (! Schema::hasColumn('engagement_candidates', 'send_status')) {
                // null = not attempted; queued | sent | skipped | blocked | failed
                $t->string('send_status', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('engagement_candidates', 'send_detail')) {
                $t->string('send_detail', 120)->nullable();
            }
            if (! Schema::hasColumn('engagement_candidates', 'sent_at')) {
                $t->timestamp('sent_at')->nullable();
            }
            if (! Schema::hasColumn('engagement_candidates', 'comm_message_id')) {
                $t->unsignedBigInteger('comm_message_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('engagement_candidates')) {
            return;
        }
        Schema::table('engagement_candidates', function (Blueprint $t) {
            foreach (['send_status', 'send_detail', 'sent_at', 'comm_message_id'] as $c) {
                if (Schema::hasColumn('engagement_candidates', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
