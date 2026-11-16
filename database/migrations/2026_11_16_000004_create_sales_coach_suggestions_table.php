<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Sales Coach (DishNet South Sudan plan, Phase 4): every reply the coach suggested to a salesperson,
 * with its reasoning, so quality monitoring can later compare what was suggested with what was sent.
 * New table only. Nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_coach_suggestions')) {
            return;
        }
        Schema::create('sales_coach_suggestions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('user_id');                 // the salesperson who asked
            $t->string('customer_phone', 40);
            $t->text('suggestion');
            $t->text('why')->nullable();
            $t->string('next_step', 24)->nullable();
            $t->json('used')->nullable();                      // knowledge / catalogue titles relied on
            $t->string('check_note', 500)->nullable();
            $t->unsignedSmallInteger('guard_removed')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'created_at'], 'sales_coach_user_idx');
            $t->index(['tenant_id', 'customer_phone'], 'sales_coach_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_coach_suggestions');
    }
};
