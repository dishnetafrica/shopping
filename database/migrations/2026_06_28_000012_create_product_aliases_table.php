<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** First-class alias data per product: aliases, search tokens, misspellings, Gujarati, plurals, short. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_aliases', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('product_id')->index();
            $t->string('alias');                          // normalized lowercase
            $t->string('type')->default('alias');         // alias|misspelling|gujarati|plural|short|token
            $t->unsignedInteger('weight')->default(100);  // signal strength 0..100
            $t->boolean('is_default')->default(false);    // e.g. "gathiya" -> Vanela Gathiya
            $t->timestamps();
            $t->index(['tenant_id', 'alias']);
        });
    }
    public function down(): void { Schema::dropIfExists('product_aliases'); }
};
