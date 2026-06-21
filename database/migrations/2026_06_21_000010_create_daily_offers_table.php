<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_offers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('offer_type', 32)->default('special_offer');   // daily_thali|special_offer|weekend_offer|festival_offer|fresh_today
            $t->string('title')->nullable();
            $t->text('description')->nullable();
            $t->integer('price')->nullable();                          // integer amount, in `currency`
            $t->string('currency', 8)->nullable();
            $t->string('image_url')->nullable();
            $t->json('structured_data')->nullable();                   // items[], day, raw, confidence, source meta
            $t->string('source', 24)->default('image');                // image|status|forward|manual
            $t->timestamp('valid_from')->nullable();
            $t->timestamp('valid_until')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index(['tenant_id', 'is_active', 'offer_type']);
            $t->index(['tenant_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_offers');
    }
};
