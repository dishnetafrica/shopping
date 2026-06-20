<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant pilot (Spicey Herbs):
 *  - orders.notes / order_items.notes  -> free-text special instructions ("less spicy", "no onion")
 *  - products.description              -> menu blurb the bot can read back ("what's in Chicken Changezi?")
 *  - orders.accepted_at / ready_at / rejected_reason -> Kitchen Board (KOT) prep-timing + reject reason
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'notes'))           $t->text('notes')->nullable()->after('location');
            if (! Schema::hasColumn('orders', 'accepted_at'))     $t->timestamp('accepted_at')->nullable()->after('delivered_at');
            if (! Schema::hasColumn('orders', 'ready_at'))        $t->timestamp('ready_at')->nullable()->after('accepted_at');
            if (! Schema::hasColumn('orders', 'rejected_reason')) $t->string('rejected_reason')->nullable()->after('ready_at');
        });

        Schema::table('order_items', function (Blueprint $t) {
            if (! Schema::hasColumn('order_items', 'notes')) $t->string('notes')->nullable()->after('qty');
        });

        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'description')) $t->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            foreach (['notes', 'accepted_at', 'ready_at', 'rejected_reason'] as $c) {
                if (Schema::hasColumn('orders', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('order_items', function (Blueprint $t) {
            if (Schema::hasColumn('order_items', 'notes')) $t->dropColumn('notes');
        });
        Schema::table('products', function (Blueprint $t) {
            if (Schema::hasColumn('products', 'description')) $t->dropColumn('description');
        });
    }
};
