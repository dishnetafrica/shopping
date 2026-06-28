<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Product Master fields that make ProductResolver data-driven (not heuristic). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'popularity'))            $t->unsignedInteger('popularity')->default(0);
            if (! Schema::hasColumn('products', 'short_name'))            $t->string('short_name')->nullable();
            if (! Schema::hasColumn('products', 'gujarati_name'))         $t->string('gujarati_name')->nullable();
            if (! Schema::hasColumn('products', 'default_variant_grams')) $t->unsignedInteger('default_variant_grams')->nullable();
            if (! Schema::hasColumn('products', 'allow_prorata'))         $t->boolean('allow_prorata')->default(true);
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['popularity', 'short_name', 'gujarati_name', 'default_variant_grams', 'allow_prorata'] as $c) {
                if (Schema::hasColumn('products', $c)) $t->dropColumn($c);
            }
        });
    }
};
