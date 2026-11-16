<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard sales pipeline (DishNet South Sudan plan, Phase 6).
 *
 * leads: nullable columns only — the pipeline stage, what qualification found, the coverage result,
 *        the next action, why it was lost, and the installation job it was handed to. Existing leads
 *        and every other business are untouched (no stage = taken from the existing status).
 * sales_pipeline_events: every stage move (who, from, to, why, when) — the history the dashboards read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'stage')) {
                $t->string('stage', 20)->nullable();
                $t->timestamp('stage_changed_at')->nullable();
                $t->json('qualification')->nullable();
                $t->string('coverage_status', 16)->nullable();
                $t->string('next_action', 200)->nullable();
                $t->string('lost_reason', 300)->nullable();
                $t->unsignedBigInteger('work_order_id')->nullable();
                $t->index(['tenant_id', 'stage'], 'leads_stage_idx');
            }
        });
        if (! Schema::hasTable('sales_pipeline_events')) {
            Schema::create('sales_pipeline_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('lead_id');
                $t->string('from_stage', 20)->nullable();
                $t->string('to_stage', 20);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('reason', 300)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['tenant_id', 'lead_id'], 'sales_pipeline_events_lead_idx');
                $t->index(['tenant_id', 'to_stage', 'created_at'], 'sales_pipeline_events_stage_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_pipeline_events');
        Schema::table('leads', function (Blueprint $t) {
            if (Schema::hasColumn('leads', 'stage')) {
                $t->dropIndex('leads_stage_idx');
                $t->dropColumn(['stage', 'stage_changed_at', 'qualification', 'coverage_status', 'next_action', 'lost_reason', 'work_order_id']);
            }
        });
    }
};
