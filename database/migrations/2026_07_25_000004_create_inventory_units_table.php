<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('product_id')->index();
            $t->string('serial');
            $t->string('manufacturer_serial')->nullable();
            $t->string('batch')->nullable();
            $t->string('status')->default('in_stock');
            $t->string('location')->nullable();
            $t->string('supplier')->nullable();
            $t->date('purchase_date')->nullable();
            $t->unsignedBigInteger('order_id')->nullable()->index();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->unsignedBigInteger('warranty_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->nullable()->index();
            $t->string('qr_token')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'serial']);
            $t->index(['tenant_id', 'product_id', 'status']);
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'qr_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_units');
    }
};
