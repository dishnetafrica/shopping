<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product Specifications (core platform capability) — the reusable spec CATALOGUE.
 *
 * One row per spec field, per tenant (e.g. Wattage in W, Battery Chemistry as a pick-list). Products
 * carry VALUES against these definitions in product_specs. This is generic: solar seeds a starter
 * catalogue; electrical/HVAC/etc. seed their own. Plain relational reference data (no event sourcing).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('spec_definitions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('key');                                   // slug, e.g. 'wattage'
            $t->string('label');                                 // 'Wattage'
            $t->string('unit')->nullable();                      // 'W','V','A','Ah','lm','K','m'
            $t->string('data_type')->default('text');            // number | text | enum | boolean
            $t->jsonb('options')->nullable();                    // enum choices, e.g. ["LiFePO4","AGM"]
            $t->string('group')->nullable();                     // 'Electrical','Battery','Physical'
            $t->boolean('filterable')->default(true);
            $t->boolean('searchable')->default(true);
            $t->integer('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->unique(['tenant_id', 'key']);                    // one definition per key per tenant
            $t->index(['tenant_id', 'active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spec_definitions');
    }
};
