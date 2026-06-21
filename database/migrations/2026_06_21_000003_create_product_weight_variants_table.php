<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_weight_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->integer('weight_grams');
            $t->decimal('price', 12, 2);
            $t->timestamps();
            $t->unique(['product_id', 'weight_grams']);
        });
    }
    public function down(): void { Schema::dropIfExists('product_weight_variants'); }
};
