<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · S1c-3 — snapshot of a line's product specifications, frozen when the document is
 * created. Additive nullable columns on invoice + quotation lines; existing rows stay null (documents
 * issued before this look exactly as before). The snapshot is filled by LineSpecSnapshotObserver.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('invoice_items') && ! Schema::hasColumn('invoice_items', 'specs_summary')) {
            Schema::table('invoice_items', fn (Blueprint $t) => $t->text('specs_summary')->nullable()->after('sku'));
        }
        if (Schema::hasTable('quotation_items') && ! Schema::hasColumn('quotation_items', 'specs_summary')) {
            Schema::table('quotation_items', fn (Blueprint $t) => $t->text('specs_summary')->nullable()->after('name'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoice_items', 'specs_summary')) {
            Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('specs_summary'));
        }
        if (Schema::hasColumn('quotation_items', 'specs_summary')) {
            Schema::table('quotation_items', fn (Blueprint $t) => $t->dropColumn('specs_summary'));
        }
    }
};
