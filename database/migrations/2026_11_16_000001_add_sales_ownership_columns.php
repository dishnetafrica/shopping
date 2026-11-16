<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales team (DishNet South Sudan plan, Phase 1): one salesperson per customer, and every staff
 * reply attributed to the person who sent it.
 *
 * Additive only: new NULLABLE columns, no existing column changed, no data rewritten. Every
 * existing row keeps NULL (= no owner / sender not recorded), which is exactly how it behaves
 * today. Ownership is used only by "Internet provider" businesses (App\Services\Sales\SalesOwnership).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_profiles', 'conversations', 'leads'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'owner_user_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->unsignedBigInteger('owner_user_id')->nullable();
                    $t->index(['tenant_id', 'owner_user_id'], $table . '_owner_idx');
                });
            }
        }
        if (Schema::hasTable('messages') && ! Schema::hasColumn('messages', 'sender_user_id')) {
            Schema::table('messages', function (Blueprint $t) {
                $t->unsignedBigInteger('sender_user_id')->nullable();   // the staff member who sent it (panel replies)
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_profiles', 'conversations', 'leads'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'owner_user_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table . '_owner_idx');
                    $t->dropColumn('owner_user_id');
                });
            }
        }
        if (Schema::hasTable('messages') && Schema::hasColumn('messages', 'sender_user_id')) {
            Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('sender_user_id'));
        }
    }
};
