<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_feed', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('source', 20);                              // owner_message|owner_image|owner_status|owner_forward
            $t->string('event_type', 24);                          // daily_offer|available|sold_out|low_stock|ready|price_change
            $t->unsignedTinyInteger('confidence')->default(0);     // 0-100
            $t->text('raw_content')->nullable();                   // OCR text / message that produced it
            $t->json('payload')->nullable();                       // {item, qty, price, title, applied}
            $t->timestamp('created_at')->nullable()->index();

            $t->index(['tenant_id', 'created_at']);
            $t->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_feed');
    }
};
