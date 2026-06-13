<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) return;

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('provider');                 // flutterwave | stripe
            $table->string('plan');                     // starter | pro
            $table->unsignedInteger('months')->default(1);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('UGX');
            $table->string('tx_ref')->unique();         // our reference
            $table->string('provider_ref')->nullable(); // flw id / stripe session/sub id
            $table->string('network')->nullable();      // MTN | AIRTEL (momo)
            $table->string('phone')->nullable();
            $table->string('status')->default('pending'); // pending | successful | failed
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
