<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS shifts (v3.9) — one cashier's till session: opening float → sales → denomination
 * count → variance. The audit record end-of-day cash-up hangs on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_shifts')) {
            return;
        }
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('operator', 120)->nullable();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();   // float + cash tenders during the shift
            $table->decimal('counted_cash', 14, 2)->nullable();    // what was physically in the drawer
            $table->decimal('variance', 14, 2)->nullable();        // counted - expected
            $table->json('denominations')->nullable();             // {"1000": 15, "500": 20, "other": 3.5}
            $table->string('currency', 8)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};
