<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.22 TenderBot — the procurement worklist: one row per product line the company must price.
 *
 * Every line the analyser found in a tender's BOQ becomes a row here, so the back office works from
 * ONE board across all open tenders ("what still needs a price?") instead of re-reading each report.
 * Prices captured here also become the price memory for the next tender that asks for the same item.
 *
 * Guarded (hasTable) like every other CloudBSS migration: safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tender_items')) {
            return;
        }
        Schema::create('tender_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('tender_id')->index();
            $t->unsignedSmallInteger('line')->default(0);        // order within the tender
            $t->string('lot', 60)->nullable();                   // multi-lot tenders
            $t->string('item', 300);
            $t->string('qty', 60)->nullable();                   // as written ("40 MT", "8,000 litres")
            $t->decimal('qty_num', 14, 3)->nullable();           // parsed number for the maths
            $t->string('unit', 40)->nullable();
            $t->text('spec')->nullable();
            $t->string('brand', 120)->nullable();
            $t->string('alt_brand', 120)->nullable();
            $t->boolean('needs_quote')->default(true);
            // to_price → quoted → priced (or skip). The board's default filter is to_price + quoted.
            $t->string('status', 12)->default('to_price')->index();
            $t->string('supplier', 160)->nullable();
            $t->decimal('unit_cost', 14, 4)->nullable();         // what WE pay
            $t->string('currency', 8)->nullable();
            $t->decimal('margin_pct', 6, 2)->nullable();         // per-line override of the tenant default
            $t->decimal('bid_unit', 14, 4)->nullable();          // what we quote the client, per unit
            $t->text('note')->nullable();
            $t->timestamp('priced_at')->nullable();
            $t->string('priced_by', 120)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_items');
    }
};
