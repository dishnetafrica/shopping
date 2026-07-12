<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap E4.5 (Constitution R7 — money is typed, never overloaded).
 *
 * The `orders.payment` column was overloaded to hold TWO different things: a sell currency
 * ("sell:USD") written by the Orders & Margin quick-order path, OR a cash/MoMo method written by
 * the seller save path — so editing an order in one screen could clobber the other meaning.
 *
 * This adds explicit `sell_currency` + `payment_method` columns and backfills them from the current
 * `payment` value. Additive + reversible. Writers/readers move onto the new columns; `payment` is
 * kept populated for backward compatibility until a later cleanup.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'sell_currency')) {
                $t->string('sell_currency', 8)->nullable()->after('payment');
            }
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $t->string('payment_method', 40)->nullable()->after('sell_currency');
            }
        });

        // Backfill from the overloaded payment column (raw DB, no model events).
        DB::table('orders')->select('id', 'payment')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $o) {
                $p  = (string) ($o->payment ?? '');
                $sc = null;
                $pm = null;
                if (strncmp($p, 'sell:', 5) === 0) {
                    $sc = strtoupper(substr($p, 5));
                } elseif ($p !== '') {
                    $pm = $p;
                }
                DB::table('orders')->where('id', $o->id)->update(['sell_currency' => $sc, 'payment_method' => $pm]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (Schema::hasColumn('orders', 'payment_method')) $t->dropColumn('payment_method');
            if (Schema::hasColumn('orders', 'sell_currency')) $t->dropColumn('sell_currency');
        });
    }
};
