<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOS Slice 6 — offline collections. A client-supplied idempotency key lets a payment queued offline be
 * replayed on reconnect without ever creating a second receipt: PaymentService::record returns the
 * existing payment when it sees a key it already stored. Nullable + indexed; online payments (no key)
 * are unaffected — many NULLs are fine. Additive and isolated.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_payments', 'idempotency_key')) {
                $t->string('idempotency_key', 64)->nullable()->after('reference');
                $t->index(['tenant_id', 'idempotency_key']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            if (Schema::hasColumn('customer_payments', 'idempotency_key')) {
                try { $t->dropIndex(['tenant_id', 'idempotency_key']); } catch (\Throwable $e) {}
                $t->dropColumn('idempotency_key');
            }
        });
    }
};
