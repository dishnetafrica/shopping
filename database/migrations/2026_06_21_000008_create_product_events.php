<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_events')) return;
        Schema::create('product_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('event', 16);                 // view | add | gallery | checkout
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'event', 'product_id'], 'pe_main_idx');
            $t->index(['tenant_id', 'created_at'], 'pe_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_events');
    }
};
