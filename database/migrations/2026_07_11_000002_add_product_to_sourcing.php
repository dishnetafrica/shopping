<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a sourcing request to the catalogue product it became, once finalised
 * ("Add to catalogue"). Lets the screen show "In catalogue ✓" and keeps the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sourcing_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id')->nullable()->after('reminder_count');
        });
    }

    public function down(): void
    {
        Schema::table('sourcing_requests', function (Blueprint $t) {
            $t->dropColumn('product_id');
        });
    }
};
