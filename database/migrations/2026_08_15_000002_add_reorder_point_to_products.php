<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product reorder point — the on-hand level at or below which this item should be bought again.
 *
 * The ONE threshold behind the Juba Stock status column and the "Below reorder" tile. Deliberately a
 * single number, not the Min/Reorder/Target trio: the recommended-purchase quantity is already
 * computed from sales velocity by Procurement\DemandSuggestion, so a second "target" here would be a
 * rival source of truth. Owners set this floor; the screen compares it against Free to sell (what the
 * shop actually counted, less what has left, less what is reserved).
 *
 * Additive and reversible: nullable, no default. NULL means "no floor set" — the product is simply
 * never judged against a reorder point, which is the correct behaviour for the ~97% of the catalogue
 * that has no count to stand behind yet. Nothing reads it until a value is entered.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'reorder_point')) {
            Schema::table('products', function (Blueprint $t) {
                $t->unsignedInteger('reorder_point')->nullable()->after('moq');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'reorder_point')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('reorder_point'));
        }
    }
};
