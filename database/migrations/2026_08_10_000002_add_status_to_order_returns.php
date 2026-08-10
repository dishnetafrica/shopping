<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.89 (ADR-019) — a return now has a life: recorded, then received.
 *
 * Wholesale goods travel back on a later truck. Crediting and restocking at the moment someone
 * SAYS two cartons are coming back puts stock on the shelf that nobody has seen and money off an
 * invoice for goods that may never arrive. So a panel return is recorded as `pending` and only
 * becomes `completed` when the goods are physically confirmed — the shape Amazon calls an RMA.
 *
 *   pending    recorded, awaiting the goods. Nothing has moved. STILL counts against the cap.
 *   completed  goods received: credit note issued, stock back, money settled.
 *   cancelled  the goods never came (or the customer changed their mind). Frees the cap again.
 *
 * DEFAULT 'completed' is deliberate: every row that exists today is a finished POS counter return,
 * and marking history as pending would reopen returns that were settled months ago. The POS till
 * keeps writing 'completed' explicitly — a customer standing at the counter with the goods in
 * their hands is the one case where recording and receiving are the same moment.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_returns', function (Blueprint $t) {
            if (! Schema::hasColumn('order_returns', 'status')) {
                $t->string('status', 12)->default('completed')->index();
            }
            if (! Schema::hasColumn('order_returns', 'received_at')) {
                $t->timestamp('received_at')->nullable();
            }
            if (! Schema::hasColumn('order_returns', 'received_by')) {
                $t->string('received_by')->nullable();
            }
            if (! Schema::hasColumn('order_returns', 'requested_json')) {
                // What was originally asked for, kept beside what actually arrived (items_json),
                // so "3 promised, 2 came back" is a fact on the record rather than a lost edit.
                $t->jsonb('requested_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $t) {
            foreach (['status', 'received_at', 'received_by', 'requested_json'] as $c) {
                if (Schema::hasColumn('order_returns', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
