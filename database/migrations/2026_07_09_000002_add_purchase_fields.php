<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Full purchase (product-line) support, reusing order_costs for lines + richer suppliers. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_costs')) {
            Schema::table('order_costs', function (Blueprint $t) {
                if (! Schema::hasColumn('order_costs','product_id')) $t->unsignedBigInteger('product_id')->nullable()->after('order_no');
                if (! Schema::hasColumn('order_costs','qty'))        $t->decimal('qty', 14, 3)->nullable()->after('product_id');
                if (! Schema::hasColumn('order_costs','unit_cost'))  $t->decimal('unit_cost', 14, 2)->nullable()->after('qty');
            });
        }
        if (Schema::hasTable('suppliers')) {
            Schema::table('suppliers', function (Blueprint $t) {
                if (! Schema::hasColumn('suppliers','pay_term_days')) $t->integer('pay_term_days')->nullable()->after('note');
                if (! Schema::hasColumn('suppliers','opening_balance')) $t->decimal('opening_balance', 14, 2)->nullable()->after('pay_term_days');
                if (! Schema::hasColumn('suppliers','opening_currency')) $t->string('opening_currency', 8)->nullable()->after('opening_balance');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_costs')) {
            Schema::table('order_costs', function (Blueprint $t) {
                foreach (['product_id','qty','unit_cost'] as $c) if (Schema::hasColumn('order_costs',$c)) $t->dropColumn($c);
            });
        }
        if (Schema::hasTable('suppliers')) {
            Schema::table('suppliers', function (Blueprint $t) {
                foreach (['pay_term_days','opening_balance','opening_currency'] as $c) if (Schema::hasColumn('suppliers',$c)) $t->dropColumn($c);
            });
        }
    }
};
