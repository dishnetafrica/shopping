<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — make the Shipment the central object. A demand plan links to one Shipment; each purchased
 * line links to the ConsignmentStock row it becomes on that shipment. Additive, reversible. The
 * Shipment + ConsignmentStock platform already exists; this only records the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demand_plans') && ! Schema::hasColumn('demand_plans', 'shipment_id')) {
            Schema::table('demand_plans', function (Blueprint $t) {
                $t->unsignedBigInteger('shipment_id')->nullable()->after('shipment_ref')->index();
            });
        }
        if (Schema::hasTable('demand_plan_lines')) {
            Schema::table('demand_plan_lines', function (Blueprint $t) {
                if (! Schema::hasColumn('demand_plan_lines', 'shipment_id')) {
                    $t->unsignedBigInteger('shipment_id')->nullable()->after('demand_plan_id')->index();
                }
                if (! Schema::hasColumn('demand_plan_lines', 'consignment_id')) {
                    $t->unsignedBigInteger('consignment_id')->nullable()->after('shipment_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('demand_plans') && Schema::hasColumn('demand_plans', 'shipment_id')) {
            Schema::table('demand_plans', fn (Blueprint $t) => $t->dropColumn('shipment_id'));
        }
        if (Schema::hasTable('demand_plan_lines')) {
            Schema::table('demand_plan_lines', function (Blueprint $t) {
                foreach (['shipment_id', 'consignment_id'] as $c) {
                    if (Schema::hasColumn('demand_plan_lines', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
