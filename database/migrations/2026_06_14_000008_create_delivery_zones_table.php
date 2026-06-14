<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->json('match_keywords')->nullable();        // ['kisaasi','kyanja',...]
            $table->double('center_lat')->nullable();
            $table->double('center_lng')->nullable();
            $table->unsignedInteger('radius_m')->nullable();
            $table->unsignedInteger('flat_fee')->default(0);
            $table->unsignedInteger('per_km_fee')->nullable();
            $table->unsignedInteger('min_fee')->default(0);
            $table->unsignedInteger('free_over')->nullable();   // subtotal >= this -> free delivery
            $table->unsignedInteger('eta_minutes')->default(45);
            $table->foreignId('default_rider_id')->nullable()->constrained('riders')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });
    }
    public function down(): void { Schema::dropIfExists('delivery_zones'); }
};
