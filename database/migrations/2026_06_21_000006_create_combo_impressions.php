<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('combo_impressions')) return;
        Schema::create('combo_impressions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('source_product_id')->nullable();
            $t->unsignedBigInteger('recommended_product_id');
            $t->string('context', 24)->default('single_product'); // single_product | after_add | checkout
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'source_product_id', 'recommended_product_id'], 'ci_pair_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_impressions');
    }
};
