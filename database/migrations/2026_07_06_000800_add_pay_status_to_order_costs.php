<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash vs credit. pay_status marks whether a cost was paid now (cash) or bought on
 * credit (we owe the supplier). paid_native = how much native currency actually left
 * hand — Cash Position sums THIS, not native_amount, so credit purchases don't wrongly
 * reduce anyone's cash. BACKFILL: every existing row is treated as fully paid (they
 * already reduced cash under the old logic), so balances do not move on deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->string('pay_status', 12)->default('cash')->after('status');   // cash | credit
            $t->decimal('paid_native', 16, 2)->default(0)->after('pay_status');
        });
        // Existing rows already moved cash under the old model → mark them fully paid.
        DB::table('order_costs')->update(['paid_native' => DB::raw('native_amount')]);
    }
    public function down(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->dropColumn(['pay_status','paid_native']);
        });
    }
};
