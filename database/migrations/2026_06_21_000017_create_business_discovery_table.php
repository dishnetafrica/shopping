<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_discovery', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('status', 16)->default('pending');     // pending|sent|approved|rejected
            $t->integer('readiness')->default(0);
            $t->json('report')->nullable();
            $t->integer('sample_messages')->default(0);
            $t->integer('sample_orders')->default(0);
            $t->string('sent_to')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_discovery');
    }
};
