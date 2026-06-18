<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Winworld MES - Phase 1 masters: Item, Machine, Material, Customer. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_items')) {
            Schema::create('ww_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('item_code', 60);
                $t->string('item_name');
                $t->string('item_group', 80)->nullable();
                $t->decimal('width_inch', 10, 3)->nullable();
                $t->decimal('length_inch', 10, 3)->nullable();
                $t->decimal('gauge', 10, 3)->nullable();
                $t->decimal('gram_per_pcs', 12, 4)->nullable(); // cached derived
                $t->string('status', 20)->default('Active');
                $t->timestamps();
                $t->unique(['tenant_id', 'item_code']);
            });
        }

        if (! Schema::hasTable('ww_machines')) {
            Schema::create('ww_machines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('process', 20);                  // Extrusion|Printing|Cutting
                $t->string('machine', 30);                  // ABA, A-1, FP-01...
                $t->decimal('max_speed', 12, 3)->nullable();
                $t->string('speed_type', 20)->nullable();   // Meter/Min|Pcs/Min|Stroke/Min
                $t->integer('cavity_repeat_pcs')->nullable();
                $t->boolean('active')->default(true);
                $t->string('remarks')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'machine']);
                $t->index(['tenant_id', 'process']);
            });
        }

        if (! Schema::hasTable('ww_materials')) {
            Schema::create('ww_materials', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('material_code', 60)->nullable();
                $t->string('material_name');
                $t->string('type', 40)->nullable();         // resin|masterbatch|additive|colour
                $t->string('uom', 12)->default('kg');
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->index(['tenant_id', 'material_name']);
            });
        }

        if (! Schema::hasTable('ww_customers')) {
            Schema::create('ww_customers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('customer_code', 60)->nullable();
                $t->string('name');
                $t->integer('credit_limit_days')->nullable();
                $t->decimal('ageing_balance', 16, 2)->default(0);  // synced from SAP
                $t->integer('overdue_days')->default(0);           // synced from SAP
                $t->string('contact')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        foreach (['ww_customers','ww_materials','ww_machines','ww_items'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
