<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_activity_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->text('message');
            $t->string('detected_event', 24)->nullable();          // available|sold_out|low_stock|ready|price_change
            $t->unsignedTinyInteger('confidence')->default(0);     // 0-100
            $t->boolean('approved')->nullable();                   // true=applied, false=ignored/declined, null=pending
            $t->timestamp('created_at')->nullable()->index();

            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_activity_log');
    }
};
