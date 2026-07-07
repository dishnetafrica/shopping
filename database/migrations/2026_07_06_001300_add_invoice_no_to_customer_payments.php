<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_payments','invoice_no')) {
                $t->string('invoice_no')->nullable()->after('order_no');
            }
        });
    }
    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            if (Schema::hasColumn('customer_payments','invoice_no')) $t->dropColumn('invoice_no');
        });
    }
};
