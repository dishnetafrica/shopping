<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human-facing task reference number, sequential PER TENANT (Family Shoppers #1, #2, #3…;
 * EA Auto Part #1, #2…). The DB `id` is global across shops and looks large/non-sequential,
 * so we keep a per-shop counter that staff can quote on WhatsApp ("done with task #7").
 *
 * `seq` was already taken (it's the workflow stage index, 0,1,2…), hence a new column.
 * Backfill walks each tenant's existing tasks oldest-first so numbering matches creation order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_tasks', 'ref_no')) {
            Schema::table('order_tasks', function (Blueprint $table) {
                $table->unsignedInteger('ref_no')->nullable()->after('id');
                // A shop never has two tasks with the same visible number.
                $table->index(['tenant_id', 'ref_no']);
            });
        }

        // Backfill: number each tenant's tasks 1..N in creation order (by id).
        $tenantIds = DB::table('order_tasks')->select('tenant_id')->distinct()->pluck('tenant_id');
        foreach ($tenantIds as $tid) {
            $n = 0;
            DB::table('order_tasks')
                ->where('tenant_id', $tid)
                ->orderBy('id')
                ->select('id')
                ->chunkById(500, function ($rows) use (&$n, $tid) {
                    foreach ($rows as $row) {
                        $n++;
                        DB::table('order_tasks')->where('id', $row->id)->update(['ref_no' => $n]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_tasks', 'ref_no')) {
            Schema::table('order_tasks', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'ref_no']);
                $table->dropColumn('ref_no');
            });
        }
    }
};
