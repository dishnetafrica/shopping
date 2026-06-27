<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daily Menu app projection: one row per tenant per date (meal buckets + specials). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_menus', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->date('menu_date')->index();
            $t->jsonb('payload_json')->nullable();               // {meals:{breakfast:[..]},specials:[..],note}
            $t->string('source')->default('owner_whatsapp');
            $t->timestamps();
            $t->unique(['tenant_id', 'menu_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('daily_menus'); }
};
