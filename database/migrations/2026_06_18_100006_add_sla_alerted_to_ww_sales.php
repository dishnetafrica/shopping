<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_sales_orders')) return;
        Schema::table('ww_sales_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('ww_sales_orders', 'sla_alerted_at')) $t->dateTime('sla_alerted_at')->nullable()->after('sla_due_at');
        });
    }
    public function down(): void
    {
        if (! Schema::hasTable('ww_sales_orders')) return;
        Schema::table('ww_sales_orders', function (Blueprint $t) {
            if (Schema::hasColumn('ww_sales_orders', 'sla_alerted_at')) $t->dropColumn('sla_alerted_at');
        });
    }
};
