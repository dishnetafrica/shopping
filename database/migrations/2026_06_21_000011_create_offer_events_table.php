<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('offer_id')->nullable()->index();   // attached offer, if any
            $t->string('item', 120)->nullable();                       // normalised item name (jalebi, fafda, thali…)
            $t->string('event_type', 24);                              // available|sold_out|low_stock|ready|price_change
            $t->json('payload')->nullable();                           // {qty, price, currency, display, raw}
            $t->timestamp('created_at')->nullable()->index();

            $t->index(['tenant_id', 'item', 'created_at']);
            $t->index(['tenant_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_events');
    }
};
