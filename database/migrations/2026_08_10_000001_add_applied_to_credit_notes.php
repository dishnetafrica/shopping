<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.88 (ADR-019) — a credit note now says whether it has been APPLIED to its invoice.
 *
 * Same two-state shape as customer payments (v4.53): money/credit is either allocated to a named
 * invoice or held on the account, never both and never neither. Without this flag the invoice
 * screen and the statement answer "what do they owe?" differently the moment goods come back —
 * which is exactly the class of bug v4.53 was written to end.
 *
 *   applied = true   → the credit came off THAT invoice's balance (staff chose "reduce what they owe")
 *   applied = false  → held on account / refunded in cash; the invoice balance is untouched
 *
 * Guarded per column so re-running on a part-migrated database is safe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $t) {
            if (! Schema::hasColumn('credit_notes', 'applied')) {
                // default FALSE: an existing credit note has never been netted off an invoice
                // balance, and back-dating one would silently change what customers owe today.
                $t->boolean('applied')->default(false)->index();
            }
            if (! Schema::hasColumn('credit_notes', 'refund_method')) {
                $t->string('refund_method', 16)->nullable();   // invoice | credit | cash | momo
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $t) {
            foreach (['applied', 'refund_method'] as $c) {
                if (Schema::hasColumn('credit_notes', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
