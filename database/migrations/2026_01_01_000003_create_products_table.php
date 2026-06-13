<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('sku')->nullable();
            $t->string('category')->nullable();
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('base_price', 12, 2)->nullable();
            $t->integer('stock')->default(0);
            $t->string('barcode')->nullable();
            $t->text('keywords')->nullable();
            $t->string('image_url')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id','name']);
            $t->index(['tenant_id','barcode']);
        });
    }
    public function down(): void { Schema::dropIfExists('products'); }
};
