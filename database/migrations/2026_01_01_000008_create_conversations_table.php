<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('customer_phone');
            $t->string('instance')->nullable();
            $t->jsonb('state')->nullable();
            $t->jsonb('cart')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id','customer_phone','instance']);
        });
    }
    public function down(): void { Schema::dropIfExists('conversations'); }
};
