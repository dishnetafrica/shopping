<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('riders')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->string('status', 16)->default('assigned'); // assigned|picked|out|delivered|failed
            $table->unsignedInteger('fee')->default(0);
            $table->double('distance_km')->nullable();
            $table->timestamp('eta_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('out_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failed_reason', 255)->nullable();
            $table->string('proof_photo_url', 512)->nullable();   // captured in D3
            $table->string('recipient_name', 120)->nullable();    // captured in D3
            $table->unsignedInteger('cod_amount')->default(0);
            $table->boolean('cod_collected')->default(false);     // reconciled fully in D4
            $table->string('rider_token', 32)->nullable();        // rider action link (D3)
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'rider_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('deliveries'); }
};
