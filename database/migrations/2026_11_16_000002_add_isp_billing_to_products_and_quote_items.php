<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internet-provider catalogue (DishNet South Sudan plan, Phase 2): whether a product is paid once or
 * every month, which service it belongs to, and its minimum contract; and on each quotation line,
 * which of the two it was quoted as.
 *
 * Additive only: nullable columns, nothing existing changed. NULL billing means "one-time", which is
 * how every product has always been sold, so no other business sees any difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'billing')) {
                $t->string('billing', 10)->nullable();          // one_off | monthly
            }
            if (! Schema::hasColumn('products', 'service_line')) {
                $t->string('service_line', 12)->nullable();     // starlink | fiber
            }
            if (! Schema::hasColumn('products', 'min_term_months')) {
                $t->unsignedSmallInteger('min_term_months')->nullable();
            }
        });
        if (Schema::hasTable('quotation_items') && ! Schema::hasColumn('quotation_items', 'billing')) {
            Schema::table('quotation_items', fn (Blueprint $t) => $t->string('billing', 10)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['billing', 'service_line', 'min_term_months'] as $c) {
                if (Schema::hasColumn('products', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        if (Schema::hasTable('quotation_items') && Schema::hasColumn('quotation_items', 'billing')) {
            Schema::table('quotation_items', fn (Blueprint $t) => $t->dropColumn('billing'));
        }
    }
};
