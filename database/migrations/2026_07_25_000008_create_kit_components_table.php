<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · S4a — Kits / Bundles. A "kit" is a PRODUCT made of other products; each row here is
 * one part of one kit ("this kit contains N of that product"). The kit and every part are ordinary
 * products, so nothing about pricing, stock or the sale engine changes — the kit just knows what it's
 * made of. Explosion into parts (on sale) is a later, separately-reviewed slice; this table is the
 * definition it will read. Additive; tenant-scoped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kit_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('kit_product_id')->index();        // the bundle product
            $t->unsignedBigInteger('component_product_id')->index();  // a part of the bundle
            $t->decimal('quantity', 12, 3)->default(1);               // how many of the part per one kit
            $t->integer('sort')->default(0);
            $t->jsonb('meta')->nullable();
            $t->timestamps();

            // One row per (kit, part): re-importing a kit replaces its parts, never duplicates them.
            $t->unique(['tenant_id', 'kit_product_id', 'component_product_id'], 'kit_component_uq');
            $t->index(['tenant_id', 'kit_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_components');
    }
};
