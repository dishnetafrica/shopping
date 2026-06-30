<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pre-pilot additions: task priority (auto-sort the inbox) and what a paused task waits on. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('order_tasks', 'priority'))    $t->unsignedTinyInteger('priority')->default(1);   // 0 low,1 normal,2 high,3 critical
            if (! Schema::hasColumn('order_tasks', 'waiting_for')) $t->string('waiting_for')->nullable();            // customer|government|supplier|internal|other
        });
    }
    public function down(): void
    {
        Schema::table('order_tasks', function (Blueprint $t) {
            foreach (['priority', 'waiting_for'] as $c) if (Schema::hasColumn('order_tasks', $c)) $t->dropColumn($c);
        });
    }
};
