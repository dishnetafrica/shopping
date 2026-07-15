<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive freight field on quotations (Q3). Freight is added on top of the priced lines + VAT to
 * form the grand total; it appears on the professional PDF and the portal, and sales set it when
 * revising. Nullable/defaulted so the existing engine is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('freight', 14, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('freight');
        });
    }
};
