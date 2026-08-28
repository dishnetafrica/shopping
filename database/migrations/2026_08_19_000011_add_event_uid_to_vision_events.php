<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency for vision events. A movement event carries a stable `uid` (sha256 of store|zone|ts|dir);
 * making (tenant_id, event_uid) unique means a re-delivered POST — the edge outbox retries at-least-once
 * — can never create a second row or a duplicate reconciliation decision. Nullable, so classical shelf
 * events without a uid are unaffected (Postgres allows many NULLs under a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vision_events') || Schema::hasColumn('vision_events', 'event_uid')) {
            return;
        }
        Schema::table('vision_events', function (Blueprint $t) {
            $t->string('event_uid', 64)->nullable()->after('event');
            $t->unique(['tenant_id', 'event_uid']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vision_events') || ! Schema::hasColumn('vision_events', 'event_uid')) {
            return;
        }
        Schema::table('vision_events', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'event_uid']);
            $t->dropColumn('event_uid');
        });
    }
};
