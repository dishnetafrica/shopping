<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds cost + actual-revenue tracking to loose stock so market sales show a real margin.
 *   unit_cost   = what each unit cost to buy (Kampala).
 *   sold_amount = running sum of actual sale revenue (qty × price at time of each sale),
 *                 so margin stays correct even when Asish sells at varying prices.
 * Margin = sold_amount − (qty_sold × unit_cost). No second COGS is posted — the purchase of
 * the stock is already booked in Kampala — so this is margin VISIBILITY, not double counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_stock', function (Blueprint $t) {
            $t->decimal('unit_cost', 14, 2)->nullable()->after('unit_price');
            $t->decimal('sold_amount', 14, 2)->default(0)->after('qty_sold');
        });
    }

    public function down(): void
    {
        Schema::table('consignment_stock', function (Blueprint $t) {
            $t->dropColumn(['unit_cost', 'sold_amount']);
        });
    }
};
