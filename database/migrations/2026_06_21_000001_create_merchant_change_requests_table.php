<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchant_change_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('merchant_phone', 32);
            $t->string('change_type', 24)->default('batch');     // batch | undo
            $t->json('payload_json');
            $t->json('previous_json')->nullable();
            $t->string('status', 12)->default('pending');         // pending|confirmed|cancelled|expired|failed
            $t->unsignedBigInteger('conversation_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('merchant_change_requests'); }
};
