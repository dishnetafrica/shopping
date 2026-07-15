<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packing step — before goods go on the bus/lorry the warehouse confirms how much was actually packed,
 * how much was missing/short at packing, and how much was damaged. Distinct from the Juba goods-receipt
 * damage (which is transit damage). Additive, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demand_plan_lines')) {
            return;
        }
        Schema::table('demand_plan_lines', function (Blueprint $t) {
            if (! Schema::hasColumn('demand_plan_lines', 'qty_packed')) {
                $t->decimal('qty_packed', 14, 3)->default(0)->after('qty_purchased');
            }
            if (! Schema::hasColumn('demand_plan_lines', 'qty_missing')) {
                $t->decimal('qty_missing', 14, 3)->default(0)->after('qty_packed');
            }
            if (! Schema::hasColumn('demand_plan_lines', 'pack_damaged')) {
                $t->decimal('pack_damaged', 14, 3)->default(0)->after('qty_missing');
            }
            if (! Schema::hasColumn('demand_plan_lines', 'packed_at')) {
                $t->timestamp('packed_at')->nullable()->after('pack_damaged');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('demand_plan_lines')) {
            return;
        }
        Schema::table('demand_plan_lines', function (Blueprint $t) {
            foreach (['qty_packed', 'qty_missing', 'pack_damaged', 'packed_at'] as $c) {
                if (Schema::hasColumn('demand_plan_lines', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
