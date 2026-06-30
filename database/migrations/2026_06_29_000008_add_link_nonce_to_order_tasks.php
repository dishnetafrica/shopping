<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Field-link hardening: a per-offer nonce (bumped on every (re)offer so old WhatsApp links
 *  die the instant a task is reassigned) and a first-opened timestamp for the audit trail. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('order_tasks', 'link_nonce')) $t->string('link_nonce', 32)->nullable();
            if (! Schema::hasColumn('order_tasks', 'opened_at'))  $t->timestamp('opened_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('order_tasks', function (Blueprint $t) {
            foreach (['link_nonce', 'opened_at'] as $c) if (Schema::hasColumn('order_tasks', $c)) $t->dropColumn($c);
        });
    }
};
