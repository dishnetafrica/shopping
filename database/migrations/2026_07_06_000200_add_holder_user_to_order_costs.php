<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each cost to the USER who holds it (not just a free-text name), so "My Cash"
 * filters reliably by account. holder (text) is kept for display; holder_user_id is
 * the robust key. Additive only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->foreignId('holder_user_id')->nullable()->after('holder')
              ->constrained('users')->nullOnDelete();
            $t->index(['tenant_id','holder_user_id','currency']);
        });
    }
    public function down(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('holder_user_id');
        });
    }
};
