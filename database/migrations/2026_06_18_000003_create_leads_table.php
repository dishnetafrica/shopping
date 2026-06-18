<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('leads')) return;

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('customer_phone', 32)->index();
            $t->string('customer_name', 120)->nullable();
            $t->string('intent', 12)->default('lead');     // lead | ticket
            $t->string('interest', 200)->nullable();       // short summary of what they want
            $t->text('message')->nullable();               // the original message
            $t->string('source', 32)->default('whatsapp');
            $t->string('status', 16)->default('new')->index(); // new|assigned|contacted|qualified|won|lost
            $t->string('assigned_to', 32)->nullable()->index(); // recipient phone
            $t->timestamp('claimed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
