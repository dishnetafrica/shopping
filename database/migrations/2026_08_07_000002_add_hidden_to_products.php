<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.54 — products that stay in the system but off the shelf.
 *
 * NULLABLE with no backfill, deliberately. Every product that exists today reads NULL, and the
 * visibility rule treats NULL as visible, so the storefront on the morning after this migration is
 * exactly the storefront of the night before. Nothing is hidden until somebody hides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'hidden')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('hidden')->nullable()->default(false)->after('active');
            });
        }

        // Every public query filters on it, so it earns an index. Partial would be tighter, but a
        // plain composite is portable and this table is not large enough for the difference to show.
        try {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['tenant_id', 'hidden'], 'products_tenant_hidden_idx');
            });
        } catch (\Throwable $e) {
            // already there, or a driver that will not take it — the column is what matters
        }
    }

    public function down(): void
    {
        try {
            Schema::table('products', fn (Blueprint $t) => $t->dropIndex('products_tenant_hidden_idx'));
        } catch (\Throwable $e) {
        }
        if (Schema::hasColumn('products', 'hidden')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('hidden'));
        }
    }
};
