<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('type', 8);                       // in | out
                $table->string('category')->default('other');    // order_payment | expense | supplier | owner_draw | other
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 8)->default('UGX');
                $table->string('method')->nullable();            // cash | momo | card | bank | other
                $table->string('received_by')->nullable();       // staff name / who recorded
                $table->string('note')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'amount_paid')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('amount_paid', 14, 2)->default(0)->after('total');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'amount_paid')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('amount_paid');
            });
        }
    }
};
