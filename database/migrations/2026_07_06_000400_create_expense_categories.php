<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preconfigured expense categories (per tenant). order_costs already stores the money;
 * an expense is a cost with stage='expense' + expense_category. This table is the
 * pick-list so staff choose from a standard set instead of typing free text.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');                    // e.g. "Rent", "Salaries", "Transport"
            $t->string('icon', 8)->nullable();     // emoji for the button
            $t->string('group')->default('operating'); // operating|staff|logistics|financial|other
            $t->boolean('active')->default(true);
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->index(['tenant_id','active']);
        });
        // add expense_category to order_costs (nullable; only used when stage='expense')
        Schema::table('order_costs', function (Blueprint $t) {
            $t->string('expense_category')->nullable()->after('stage');
        });
    }
    public function down(): void
    {
        Schema::table('order_costs', function (Blueprint $t) { $t->dropColumn('expense_category'); });
        Schema::dropIfExists('expense_categories');
    }
};
