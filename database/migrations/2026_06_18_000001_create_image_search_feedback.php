<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('image_search_feedback')) return;

        Schema::create('image_search_feedback', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('image_hash', 64)->index();        // sha1 of the photo → dedupe / future short-circuit
            $t->string('vision_query', 160);              // what vision read the photo as
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('product_name', 200)->nullable();  // the product the customer actually chose
            $t->unsignedTinyInteger('confidence')->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_search_feedback');
    }
};
