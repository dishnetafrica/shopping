<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Full WhatsApp transcript — one row per message, inbound or outbound.
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('customer_phone')->index();
            $t->string('instance')->nullable();
            $t->string('direction', 3);            // 'in' | 'out'
            $t->string('sender', 16);              // customer | bot | agent | system
            $t->text('body');
            $t->string('wa_message_id')->nullable();
            $t->string('status')->nullable();      // sent | delivered | read (future)
            $t->json('meta')->nullable();
            $t->timestamps();
            // thread fetch: a customer's messages for a tenant, in order
            $t->index(['tenant_id', 'customer_phone', 'id']);
            $t->index(['tenant_id', 'created_at']);
        });

        // Extend conversations so the inbox list can sort/badge and a human can take over.
        Schema::table('conversations', function (Blueprint $t) {
            $t->boolean('agent_active')->default(false);   // human took over -> bot stays quiet
            $t->unsignedInteger('unread')->default(0);     // unread inbound count for the badge
            $t->timestamp('last_inbound_at')->nullable();  // last time the customer wrote
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropColumn(['agent_active', 'unread', 'last_inbound_at']);
        });
    }
};
