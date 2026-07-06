<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_group_id — links the several order_costs rows that come from ONE physical
 * bulk purchase (paid once, one receipt) split across 3-4 orders. Lets the panel show
 * "1 buy -> N orders" and void/delete the whole group together. Additive only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->string('purchase_group_id', 40)->nullable()->after('created_by')->index();
        });
    }
    public function down(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->dropColumn('purchase_group_id');
        });
    }
};
