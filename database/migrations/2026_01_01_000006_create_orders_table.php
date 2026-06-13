<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('order_no')->index();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->text('items_text')->nullable();
            $t->jsonb('items_json')->nullable();
            $t->decimal('total', 12, 2)->default(0);
            $t->string('location')->nullable();
            $t->string('payment')->nullable();
            $t->string('status')->default('New');
            $t->string('channel')->default('whatsapp');  // whatsapp|web|pos
            $t->foreignId('rider_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('track_token')->nullable()->index();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('orders'); }
};
