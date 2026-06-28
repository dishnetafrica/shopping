<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Track status freshness so the system can push sellers to keep order data current, and record who closed the loop. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'status_changed_at')) $t->timestamp('status_changed_at')->nullable()->index();
            if (! Schema::hasColumn('orders', 'stale_nudged_at'))   $t->timestamp('stale_nudged_at')->nullable();
            if (! Schema::hasColumn('orders', 'stale_level'))        $t->unsignedTinyInteger('stale_level')->default(0);
            if (! Schema::hasColumn('orders', 'delivered_by'))       $t->string('delivered_by')->nullable();   // seller | customer
        });
    }
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            foreach (['status_changed_at', 'stale_nudged_at', 'stale_level', 'delivered_by'] as $c) {
                if (Schema::hasColumn('orders', $c)) $t->dropColumn($c);
            }
        });
    }
};
