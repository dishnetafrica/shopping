<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('image_search_feedback')) return;
        if (Schema::hasColumn('image_search_feedback', 'customer_phone')) return;

        Schema::table('image_search_feedback', function (Blueprint $t) {
            // Who made the selection — so a "known" image needs agreement from several
            // distinct customers, not one person repeating the same (possibly wrong) pick.
            $t->string('customer_phone', 32)->nullable()->after('tenant_id')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('image_search_feedback') && Schema::hasColumn('image_search_feedback', 'customer_phone')) {
            Schema::table('image_search_feedback', function (Blueprint $t) {
                $t->dropColumn('customer_phone');
            });
        }
    }
};
