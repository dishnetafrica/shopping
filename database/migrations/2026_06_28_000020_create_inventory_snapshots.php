<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory CONFIDENCE as an observation, not a product property. One current snapshot per
 * (product, location) so the same product can hold different confidence in the shop, the warehouse,
 * a rider's bag, or a branch. observed_at drives automatic confidence decay.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_snapshots', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('product_id')->index();
            $t->string('location')->default('default');     // shop | warehouse | rider:<id> | branch:<id> | supplier
            $t->integer('quantity')->nullable();
            $t->string('confidence')->default('unknown');    // exact | estimated | unknown
            $t->string('source')->nullable();                // count | adjustment | sale | owner
            $t->timestamp('observed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'product_id', 'location']);
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_snapshots'); }
};
