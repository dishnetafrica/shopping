<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods receipt on procurement lines (Phase 3 on-ramp). When goods land in Juba the received quantity
 * is recorded here AND written into the real StockLedger (Juba) — so accurate inventory accumulates
 * shipment by shipment, starting clean, without ever seeding the fake catalog numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demand_plan_lines')) {
            return;
        }
        Schema::table('demand_plan_lines', function (Blueprint $t) {
            if (! Schema::hasColumn('demand_plan_lines', 'received_qty')) {
                $t->decimal('received_qty', 14, 3)->default(0)->after('qty_purchased');
            }
            if (! Schema::hasColumn('demand_plan_lines', 'damaged_qty')) {
                $t->decimal('damaged_qty', 14, 3)->default(0)->after('received_qty');
            }
            if (! Schema::hasColumn('demand_plan_lines', 'received_at')) {
                $t->timestamp('received_at')->nullable()->after('damaged_qty');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('demand_plan_lines')) {
            return;
        }
        Schema::table('demand_plan_lines', function (Blueprint $t) {
            foreach (['received_qty', 'damaged_qty', 'received_at'] as $c) {
                if (Schema::hasColumn('demand_plan_lines', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
