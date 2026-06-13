<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();                 // subdomain
            $t->string('status')->default('active');
            $t->string('plan')->default('starter');
            $t->string('whatsapp_driver')->default('evolution');
            $t->string('whatsapp_instance')->nullable()->index(); // maps inbound msgs -> tenant
            $t->string('whatsapp_number')->nullable();
            $t->string('order_prefix')->default('ORD');
            $t->jsonb('settings')->nullable();            // currency, rates, discount, delivery, branding
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('tenants'); }
};
