<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 double-entry ledger foundation.
 * ADDITIVE ONLY — creates three new tables. Does NOT touch order_costs / customer_payments.
 * Safe to run alongside the existing cashbook; nothing reads these tables until the
 * ledger reports are opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts
        if (! Schema::hasTable('ledger_accounts')) {
            Schema::create('ledger_accounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('code', 20);                 // e.g. 1010
                $t->string('name');                     // Cash on Hand — USD
                $t->enum('type', ['asset','liability','equity','income','expense']);
                $t->enum('normal', ['debit','credit']); // normal balance side
                $t->string('currency', 8)->nullable();  // for cash/bank accounts held in one currency
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['tenant_id','code']);
            });
        }

        // Journals (one balanced document per business event)
        if (! Schema::hasTable('ledger_journals')) {
            Schema::create('ledger_journals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('ref', 40)->nullable();          // human ref (JV-000001)
                $t->string('source', 32)->default('manual'); // order_cost | customer_payment | manual | reversal
                $t->unsignedBigInteger('source_id')->nullable()->index(); // e.g. order_costs.id
                $t->string('memo', 500)->nullable();
                $t->decimal('amount_usd', 18, 2)->default(0); // total (one side) for convenience
                $t->enum('status', ['posted','void'])->default('posted');
                $t->unsignedBigInteger('reversed_by')->nullable(); // journal id that reversed this
                $t->string('created_by')->nullable();
                $t->timestamp('posted_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id','source','source_id']);
            });
        }

        // Journal lines (the debits and credits — this is the general ledger)
        if (! Schema::hasTable('ledger_lines')) {
            Schema::create('ledger_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('journal_id')->index();
                $t->unsignedBigInteger('account_id')->index();
                $t->decimal('debit_usd', 18, 2)->default(0);
                $t->decimal('credit_usd', 18, 2)->default(0);
                // native memo (foreign-currency accounts keep their native figure for reconciliation)
                $t->string('currency', 8)->nullable();
                $t->decimal('native_amount', 18, 2)->nullable();
                $t->string('memo', 500)->nullable();
                $t->timestamps();
                $t->index(['tenant_id','account_id']);
            });
        }
        // Per-tenant ledger config (controls dormant/live write-time posting)
        if (! Schema::hasTable('ledger_config')) {
            Schema::create('ledger_config', function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->primary();
                $t->boolean('live_posting')->default(false);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_config');
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_journals');
        Schema::dropIfExists('ledger_accounts');
    }
};
