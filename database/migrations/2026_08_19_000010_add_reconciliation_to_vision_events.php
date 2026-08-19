<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-0 Stock & Loss: first-class reconciliation result on a vision movement event, so the review
 * surface can query 🟠 pending / 🔴 unreconciled without digging through the payload JSON. Additive
 * and nullable — shelf events simply leave these null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vision_events')) {
            return;
        }
        Schema::table('vision_events', function (Blueprint $t) {
            if (! Schema::hasColumn('vision_events', 'recon_result')) {
                $t->string('recon_result', 20)->nullable()->after('to_state');   // MATCH|PENDING|UNRECONCILED|…
            }
            if (! Schema::hasColumn('vision_events', 'recon_severity')) {
                $t->string('recon_severity', 8)->nullable()->after('recon_result'); // green|amber|red|info|mute
            }
            if (! Schema::hasColumn('vision_events', 'review_required')) {
                $t->boolean('review_required')->default(false)->after('recon_severity');
            }
            if (! Schema::hasColumn('vision_events', 'reconciled_at')) {
                $t->timestamp('reconciled_at')->nullable()->after('review_required');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vision_events')) {
            return;
        }
        Schema::table('vision_events', function (Blueprint $t) {
            foreach (['recon_result', 'recon_severity', 'review_required', 'reconciled_at'] as $c) {
                if (Schema::hasColumn('vision_events', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
