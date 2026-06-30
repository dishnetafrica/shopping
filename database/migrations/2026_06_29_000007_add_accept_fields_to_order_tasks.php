<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accept handshake for dispatched (field) tasks: when it was accepted, and when we last
 *  nudged an un-accepted offer (reminder before escalation). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('order_tasks', 'accepted_at')) $t->timestamp('accepted_at')->nullable();
            if (! Schema::hasColumn('order_tasks', 'reminded_at')) $t->timestamp('reminded_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('order_tasks', function (Blueprint $t) {
            foreach (['accepted_at', 'reminded_at'] as $c) if (Schema::hasColumn('order_tasks', $c)) $t->dropColumn($c);
        });
    }
};
