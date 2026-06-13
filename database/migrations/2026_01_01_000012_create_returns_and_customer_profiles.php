<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('customer_phone')->nullable()->index();
            $t->string('customer_name')->nullable();
            $t->text('items_text')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('resolution')->default('adjust'); // adjust | credit | refund | redeem
            $t->string('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('customer_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('phone')->index();
            $t->string('name')->nullable();
            $t->string('alt_phone')->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('lang')->nullable();
            $t->string('greeting')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_returns');
        Schema::dropIfExists('customer_profiles');
    }
};
