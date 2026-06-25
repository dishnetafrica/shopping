<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('quotations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('quote_no')->index();              // FS-Q260625-XXXX (unique in practice)
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->index();
            $t->string('currency', 8)->default('UGX');
            $t->decimal('total', 14, 2)->default(0);
            $t->string('status')->default('sent')->index(); // sent|accepted|declined|converted|expired
            $t->string('source')->default('panel');          // panel|bot
            $t->date('valid_until')->nullable();
            $t->string('pdf_path')->nullable();
            $t->unsignedBigInteger('order_id')->nullable()->index(); // set when converted
            $t->unsignedInteger('send_count')->default(1);
            $t->timestamp('last_sent_at')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('quotation_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('quotation_id')->index();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->unsignedInteger('qty')->default(1);
            $t->decimal('unit_price', 14, 2)->default(0);
            $t->decimal('line_total', 14, 2)->default(0);
            $t->string('unit_label')->nullable();
            $t->text('image_url')->nullable();
            $t->boolean('matched')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
