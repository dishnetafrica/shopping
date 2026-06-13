<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('plan');
            }
            if (! Schema::hasColumn('tenants', 'paid_until')) {
                $table->timestamp('paid_until')->nullable()->after('trial_ends_at');
            }
            if (! Schema::hasColumn('tenants', 'billing_note')) {
                $table->string('billing_note')->nullable()->after('paid_until');
            }
        });

        // Grandfather any business that existed before plans were introduced:
        // keep them on full features so nothing they rely on suddenly locks.
        DB::table('tenants')
            ->whereNull('paid_until')
            ->whereNull('trial_ends_at')
            ->update([
                'plan'         => 'pro',
                'paid_until'   => now()->addYears(10),
                'billing_note' => 'Grandfathered (existed before plans)',
            ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            foreach (['trial_ends_at', 'paid_until', 'billing_note'] as $c) {
                if (Schema::hasColumn('tenants', $c)) $table->dropColumn($c);
            }
        });
    }
};
