<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('sold_by_weight')->default(false);
            $t->string('weight_unit', 8)->default('gram');           // gram | kg
            $t->integer('reference_weight_grams')->default(1000);
            $t->decimal('reference_price', 12, 2)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['sold_by_weight', 'weight_unit', 'reference_weight_grams', 'reference_price']);
        });
    }
};
