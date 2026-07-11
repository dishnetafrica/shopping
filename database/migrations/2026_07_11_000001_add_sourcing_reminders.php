<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder tracking for sourcing requests, so the system can auto-ask Jayesh for the Juba
 * price and keep nudging until he replies:
 *   notified_at      — when we first WhatsApp'd the buyer.
 *   last_reminded_at — when we last nudged.
 *   reminder_count   — how many nudges sent (capped so it can never spam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sourcing_requests', function (Blueprint $t) {
            $t->timestamp('notified_at')->nullable()->after('status');
            $t->timestamp('last_reminded_at')->nullable()->after('notified_at');
            $t->unsignedSmallInteger('reminder_count')->default(0)->after('last_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('sourcing_requests', function (Blueprint $t) {
            $t->dropColumn(['notified_at', 'last_reminded_at', 'reminder_count']);
        });
    }
};
