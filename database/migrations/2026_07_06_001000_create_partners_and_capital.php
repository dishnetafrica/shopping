<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partnership capital.
 *  partners         — the people who own the business + their ownership %.
 *  partner_capital  — the capital ledger: opening balances, capital introduced, drawings.
 * Every capital movement is also mirrored as a cash entry in order_costs (funding/drawing)
 * so Cash Position and the capital accounts stay consistent. Additive, isolated tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->unsignedBigInteger('user_id')->nullable();          // links capital cash to this person's float
            $t->decimal('ownership_pct', 6, 2)->default(0);          // set manually (unequal)
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id','name']);
        });
        Schema::create('partner_capital', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16);                                  // opening | introduced | drawing
            $t->string('currency', 8);
            $t->decimal('native_amount', 16, 2)->default(0);
            $t->decimal('rate_to_usd', 16, 6)->default(1);
            $t->decimal('usd_amount', 16, 2)->default(0);
            $t->string('note')->nullable();
            $t->unsignedBigInteger('order_cost_id')->nullable();     // the mirrored cash row
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id','partner_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('partner_capital');
        Schema::dropIfExists('partners');
    }
};
