<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_live_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->integer('overall_score')->default(0);
            $t->string('classification', 32)->nullable();
            $t->string('recommended_mode', 32)->nullable();
            $t->json('category_scores')->nullable();
            $t->json('recommendations')->nullable();
            $t->string('status', 16)->default('pending');     // pending|approved
            $t->string('approved_mode', 32)->nullable();       // set only when owner picks a mode
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_live_reports');
    }
};
