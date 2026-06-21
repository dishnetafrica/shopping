<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_validations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('business_name')->nullable();
            $t->string('business_type', 32)->index();
            $t->string('status', 16)->default('enrolled');     // enrolled|scanned|reviewed|live

            $t->integer('messages_scanned')->default(0);
            $t->integer('products_found')->default(0);
            $t->integer('faq_found')->default(0);
            $t->integer('delivery_rules_found')->default(0);
            $t->integer('readiness_score')->default(0);

            $t->integer('actual_accuracy')->default(0);
            $t->integer('owner_approved_accuracy')->default(0);
            $t->integer('owner_edits_required')->default(0);
            $t->integer('owner_corrections_pct')->default(0);
            $t->integer('time_to_go_live_min')->nullable();

            $t->json('detail')->nullable();
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('went_live_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_validations');
    }
};
