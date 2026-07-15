<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-wise procurement — material goes to Juba every day (bus or lorry), so a demand plan is really a
 * daily request. Adds the dispatch date, transport mode, and how many days of demand the plan covers
 * (so the suggestion scales to a day, not a month). Additive, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demand_plans')) {
            return;
        }
        Schema::table('demand_plans', function (Blueprint $t) {
            if (! Schema::hasColumn('demand_plans', 'plan_date')) {
                $t->date('plan_date')->nullable()->after('title')->index();
            }
            if (! Schema::hasColumn('demand_plans', 'transport_mode')) {
                $t->string('transport_mode', 16)->nullable()->after('plan_date');  // bus | lorry
            }
            if (! Schema::hasColumn('demand_plans', 'cover_days')) {
                $t->unsignedSmallInteger('cover_days')->default(1)->after('transport_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('demand_plans')) {
            return;
        }
        Schema::table('demand_plans', function (Blueprint $t) {
            foreach (['plan_date', 'transport_mode', 'cover_days'] as $c) {
                if (Schema::hasColumn('demand_plans', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
