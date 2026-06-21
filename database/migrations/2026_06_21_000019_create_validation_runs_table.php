<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();   // null for fixture runs
            $t->string('business_type', 32);
            $t->integer('messages_scanned')->default(0);
            $t->integer('products_found')->default(0);
            $t->integer('faq_found')->default(0);
            $t->integer('delivery_rules_found')->default(0);
            $t->integer('readiness_score')->default(0);
            $t->integer('accuracy_score')->default(0);
            $t->integer('scan_ms')->nullable();
            $t->json('detail')->nullable();
            $t->timestamp('created_at')->nullable()->index();

            $t->index('business_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_runs');
    }
};
