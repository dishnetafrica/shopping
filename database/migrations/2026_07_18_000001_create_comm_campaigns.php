<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Center M4 — Campaigns.
 *
 * ONE new table. Recipients are NOT copied into a campaign_recipients table —
 * every send is a normal comm_messages row (related_type='campaign',
 * related_id=<campaign id>), so suppression, failover, retry, tracking and the
 * Queue & Logs screen all work on campaign mail for free. Stats are computed
 * from comm_messages/comm_events, never stored twice.
 *
 * Batching is cursor-based (`last_enqueued_id` over customer_profiles.id):
 * restart-safe, and `throttle_per_minute` = the batch size the minute sweep
 * enqueues, which is what keeps a 5k blast from torching a provider limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comm_campaigns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('channel', 20)->default('email');   // email|whatsapp|both
            $t->string('template_key', 60)->nullable();    // null = inline content below
            $t->string('subject')->nullable();
            $t->longText('html_body')->nullable();
            $t->longText('text_body')->nullable();
            $t->json('segment')->nullable();               // {segment, city, ordered_within_days, not_ordered_within_days}
            $t->string('status', 15)->default('draft');    // draft|scheduled|sending|paused|done|cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('total_estimated')->default(0);
            $t->unsignedInteger('enqueued_count')->default(0);
            $t->unsignedInteger('throttle_per_minute')->default(60);
            $t->unsignedBigInteger('last_enqueued_id')->default(0);  // customer_profiles.id cursor
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status'], 'comm_campaigns_state_idx');
            $t->index(['status', 'scheduled_at'], 'comm_campaigns_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comm_campaigns');
    }
};
