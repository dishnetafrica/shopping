<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS Returns (v4.0) — extend the legacy order_returns register with the structure the till
 * needs: per-line quantities (so nobody can return more than was bought), the refund method,
 * the credit-note linkage, and WHO processed it (shift cash math). Additive + guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_returns')) {
            return;   // legacy table absent — nothing to extend (returns flow will refuse politely)
        }
        Schema::table('order_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('order_returns', 'items_json')) {
                $table->json('items_json')->nullable();
            }
            if (! Schema::hasColumn('order_returns', 'refund_method')) {
                $table->string('refund_method', 20)->nullable();   // cash | momo | store_credit
            }
            if (! Schema::hasColumn('order_returns', 'credit_note_no')) {
                $table->string('credit_note_no', 40)->nullable();
            }
            if (! Schema::hasColumn('order_returns', 'order_no')) {
                $table->string('order_no', 60)->nullable()->index();
            }
            if (! Schema::hasColumn('order_returns', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
            if (! Schema::hasColumn('order_returns', 'operator')) {
                $table->string('operator', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive columns are left in place on rollback (safe, and data-preserving).
    }
};
