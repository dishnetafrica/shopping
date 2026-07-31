<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI campaign drafts (v4.5) — one PERSONALISED message per (campaign, customer), written by
 * the AI from relationship memory, reviewed by a human, sent PACED by aic:send-tick.
 * Rides on the existing campaigns table + campaign_messages idempotency claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_campaign_drafts')) {
            return;
        }
        Schema::create('ai_campaign_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->string('phone', 40);
            $table->string('name', 160)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('draft');   // draft|approved|skipped|sent|failed
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'phone']);
            $table->index(['tenant_id', 'campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_drafts');
    }
};
