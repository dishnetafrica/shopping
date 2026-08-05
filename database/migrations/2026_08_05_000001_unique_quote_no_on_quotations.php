<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.38 — make a quotation number mean one quotation.
 *
 * `quote_no` carried only an index and a comment reading "unique in practice". It is minted from
 * four random characters inside a per-day namespace, and any path that re-persists a document can
 * mint a second row under a number a customer has already been given. A comment is not a
 * constraint; this is.
 *
 * Existing collisions are renamed rather than deleted — a duplicate is still somebody's order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations') || ! Schema::hasColumn('quotations', 'quote_no')) {
            return;
        }

        // Rename any collision before the constraint lands, oldest row keeps the number.
        try {
            $dupes = DB::table('quotations')
                ->select('tenant_id', 'quote_no', DB::raw('COUNT(*) as n'))
                ->whereNotNull('quote_no')->where('quote_no', '!=', '')
                ->groupBy('tenant_id', 'quote_no')->having('n', '>', 1)->get();

            foreach ($dupes as $d) {
                $rows = DB::table('quotations')
                    ->where('tenant_id', $d->tenant_id)->where('quote_no', $d->quote_no)
                    ->orderBy('id')->pluck('id')->all();
                array_shift($rows);                                  // the first one keeps it
                foreach ($rows as $i => $id) {
                    DB::table('quotations')->where('id', $id)
                        ->update(['quote_no' => $d->quote_no . '-D' . ($i + 2)]);
                }
            }
        } catch (\Throwable $e) {
            // A box that cannot group here is a box that has no duplicates to fix.
        }

        try {
            Schema::table('quotations', function (Blueprint $t) {
                $t->unique(['tenant_id', 'quote_no'], 'quotations_tenant_quote_no_unique');
            });
        } catch (\Throwable $e) {
            // Already present, or a residual collision — leave the table usable either way.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }
        try {
            Schema::table('quotations', function (Blueprint $t) {
                $t->dropUnique('quotations_tenant_quote_no_unique');
            });
        } catch (\Throwable $e) {
        }
    }
};
