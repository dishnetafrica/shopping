<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the outcome decision when a priced request is NOT stocked (e.g. price too high from
 * Kampala), so "why we didn't stock X" is kept instead of silently closing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sourcing_requests', function (Blueprint $t) {
            $t->string('decision_note')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('sourcing_requests', function (Blueprint $t) {
            $t->dropColumn('decision_note');
        });
    }
};
