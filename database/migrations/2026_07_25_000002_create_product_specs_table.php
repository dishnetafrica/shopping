<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product Specifications — the VALUES (one row per product × spec definition).
 *
 * A number spec is stored in BOTH value_number (for range filtering / sorting: "≥ 400 W") and
 * value_text (the display). Text / enum / boolean specs use value_text (with value_number carrying
 * 0/1 for booleans). Keyed to the product; the products table is never touched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_specs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('product_id')->index();
            $t->unsignedBigInteger('spec_definition_id')->index();
            $t->string('value_text')->nullable();                 // display value ('LiFePO4','IP65','550')
            $t->decimal('value_number', 20, 4)->nullable();       // numeric value for filter/sort
            $t->timestamps();

            $t->unique(['tenant_id', 'product_id', 'spec_definition_id'], 'product_specs_unique');
            // Filter/sort by a spec's numeric value across products (e.g. wattage >= 400).
            $t->index(['tenant_id', 'spec_definition_id', 'value_number'], 'product_specs_num_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specs');
    }
};
