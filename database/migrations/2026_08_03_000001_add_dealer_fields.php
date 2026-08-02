<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.9 — Dealer Programme drop 4a (approved spec, claude/DEALER-PROGRAMME-SPEC.md).
 *
 * Dealers are fields on the phone-keyed CustomerProfile the bot already remembers — no new
 * identity system. products.dealer_price is the per-product negotiated override that beats the
 * tier percentage. Everything is nullable: null tier = ordinary retail customer, null override =
 * tier percentage applies. Guarded so re-runs are no-ops.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_profiles', 'dealer_tier')) {
            Schema::table('customer_profiles', function (Blueprint $t) {
                $t->string('dealer_tier', 20)->nullable()->index();   // null | dealer | wholesale
                $t->decimal('dealer_discount_pct', 5, 2)->nullable(); // per-customer override of the tier default
                $t->timestamp('dealer_since')->nullable();
                $t->string('dealer_notes', 300)->nullable();
            });
        }
        if (! Schema::hasColumn('products', 'dealer_price')) {
            Schema::table('products', function (Blueprint $t) {
                $t->decimal('dealer_price', 12, 2)->nullable();       // negotiated per-product override
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profiles', 'dealer_tier')) {
            Schema::table('customer_profiles', function (Blueprint $t) {
                $t->dropColumn(['dealer_tier', 'dealer_discount_pct', 'dealer_since', 'dealer_notes']);
            });
        }
        if (Schema::hasColumn('products', 'dealer_price')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropColumn('dealer_price');
            });
        }
    }
};
