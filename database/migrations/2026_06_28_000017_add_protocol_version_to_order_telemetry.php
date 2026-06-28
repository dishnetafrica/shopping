<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stamp the order/quote protocol version on telemetry so disagreement shifts can be correlated to it. */
return new class extends Migration {
    public function up(): void
    {
        foreach (['order_flow_metrics', 'shadow_diffs'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'protocol_version')) $t->unsignedSmallInteger('protocol_version')->default(1)->index();
            });
        }
    }
    public function down(): void
    {
        foreach (['order_flow_metrics', 'shadow_diffs'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'protocol_version')) $t->dropColumn('protocol_version');
            });
        }
    }
};
