<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an ad-hoc task to a customer (customer_profiles.id), so a "Collect 68 USD from Mehul"
 * task created off the customer page can be shown back on that customer and named in the Tasks
 * list. Nullable — order-workflow tasks and un-linked ad-hoc tasks simply leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_tasks', 'customer_id')) {
            Schema::table('order_tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('order_id');
                $table->index(['tenant_id', 'customer_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_tasks', 'customer_id')) {
            Schema::table('order_tasks', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'customer_id']);
                $table->dropColumn('customer_id');
            });
        }
    }
};
