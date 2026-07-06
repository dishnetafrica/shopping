<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * suppliers — who we buy FROM (distinct from holder = who paid). Plus supplier_id/name on
 * order_costs so every purchase records its source, enabling per-supplier spend + price
 * history later. Additive: new table + two nullable columns; no existing data touched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id','name']);
        });
        Schema::table('order_costs', function (Blueprint $t) {
            $t->unsignedBigInteger('supplier_id')->nullable()->after('holder_user_id')->index();
            $t->string('supplier_name')->nullable()->after('supplier_id');
        });
    }
    public function down(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            $t->dropColumn(['supplier_id','supplier_name']);
        });
        Schema::dropIfExists('suppliers');
    }
};
