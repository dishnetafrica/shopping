<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sent / Packed split — "purchased" and "loaded onto today's transport" are different. Records how
 * much of a purchased line actually went on the bus/lorry, so a partial dispatch (bought 100, sent 80)
 * is exact and "what went today" is answerable. Additive, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demand_plan_lines')) {
            return;
        }
        Schema::table('demand_plan_lines', function (Blueprint $t) {
            if (! Schema::hasColumn('demand_plan_lines', 'qty_sent')) {
                $t->decimal('qty_sent', 14, 3)->default(0)->after('qty_purchased');
            }
            if (! Schema::hasColumn('demand_plan_lines', 'sent_at')) {
                $t->timestamp('sent_at')->nullable()->after('qty_sent');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('demand_plan_lines')) {
            return;
        }
        Schema::table('demand_plan_lines', function (Blueprint $t) {
            foreach (['qty_sent', 'sent_at'] as $c) {
                if (Schema::hasColumn('demand_plan_lines', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
