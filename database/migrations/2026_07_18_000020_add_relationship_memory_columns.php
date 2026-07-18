<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V4.0 Relationship Memory + Stage (Guardian-ready from day one).
 * Idempotent per house rules — safe to rerun, safe on tenants that already have it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_profiles', 'facts'))             $t->jsonb('facts')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'ai_summary'))        $t->text('ai_summary')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'prefs'))             $t->jsonb('prefs')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'stage'))             $t->string('stage', 24)->nullable()->index();
            if (! Schema::hasColumn('customer_profiles', 'stage_changed_at'))  $t->timestamp('stage_changed_at')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'health'))            $t->unsignedSmallInteger('health')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'health_prev'))       $t->unsignedSmallInteger('health_prev')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'health_flags'))      $t->jsonb('health_flags')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'last_summarized_at')) $t->timestamp('last_summarized_at')->nullable();
        });

        Schema::table('conversations', function (Blueprint $t) {
            if (! Schema::hasColumn('conversations', 'summarized_at'))  $t->timestamp('summarized_at')->nullable()->index();
            if (! Schema::hasColumn('conversations', 'last_sentiment')) $t->string('last_sentiment', 16)->nullable();
            if (! Schema::hasColumn('conversations', 'review'))         $t->jsonb('review')->nullable();
            if (! Schema::hasColumn('conversations', 'quality'))        $t->string('quality', 20)->nullable()->index();
            if (! Schema::hasColumn('conversations', 'reviewed_at'))    $t->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        // additive columns; intentionally no destructive down (house rollback-safety rule)
    }
};
