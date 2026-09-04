<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product retail PIECE price — the price of a single piece out of a carton, set independently of
 * the carton (box / wholesale) price in `price`.
 *
 * Until now the loose per-piece price was derived from one global markup (`carton ÷ pack × (1+markup)`),
 * so a shop could not make one part's retail piece price cheaper without moving its box price or the
 * global markup. This column lets the owner set the box price and the piece price as two independent
 * numbers (box = `price`, piece = `piece_price`).
 *
 * Additive and reversible: nullable, no default. NULL / 0 means "no explicit piece price" — the loose
 * price falls back to the existing carton÷pack×markup formula, so every product that doesn't set one
 * behaves exactly as before. Nothing charges from it unless a positive value is entered AND the tenant
 * has loose selling enabled.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'piece_price')) {
            Schema::table('products', function (Blueprint $t) {
                $t->decimal('piece_price', 14, 4)->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'piece_price')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('piece_price'));
        }
    }
};
