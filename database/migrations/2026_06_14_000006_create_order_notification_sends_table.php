<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notification_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('order_notification_recipients')->cascadeOnDelete();
            $table->string('event_type', 32)->default('order_placed');
            $table->timestamp('sent_at')->nullable();
            $table->string('message_id', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            // one notification per (order, recipient, event) — the idempotency guard
            $table->unique(['order_id', 'recipient_id', 'event_type']);
            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notification_sends');
    }
};
