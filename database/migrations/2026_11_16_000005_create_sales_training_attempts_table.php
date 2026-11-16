<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales training and certification (DishNet South Sudan plan, Phase 5): every assessment sitting.
 * The questions are copied into the sitting when it starts, so a later change to the question bank
 * never changes a result. New table only. Nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_training_attempts')) {
            return;
        }
        Schema::create('sales_training_attempts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('user_id');
            $t->string('status', 16)->default('in_progress');      // in_progress | submitted
            $t->json('questions');                                   // the sitting's questions, with the answer key
            $t->json('quiz_versions')->nullable();                   // which published quiz versions they came from
            $t->json('answers')->nullable();
            $t->json('module_scores')->nullable();
            $t->decimal('score', 5, 1)->nullable();
            $t->boolean('passed')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'status'], 'sales_training_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_training_attempts');
    }
};
