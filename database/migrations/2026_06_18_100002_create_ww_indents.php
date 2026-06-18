<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Winworld MES - Phase 1 Order Indent Form (WIL/MKT/OIF/001).
 * Header is wide and faithful to the single controlled form; blending
 * lines and per-process QC sign-offs are child tables.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_indents')) {
            Schema::create('ww_indents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('indent_no', 40);
                $t->string('doc_ref', 40)->default('WIL/MKT/OIF/001');
                $t->unsignedBigInteger('customer_id')->nullable()->index();
                $t->string('customer_name');                 // denormalised for the form
                $t->unsignedBigInteger('item_id')->nullable()->index();
                $t->string('product_name');
                $t->string('sales_person')->nullable();
                $t->date('date_of_indent')->nullable();
                $t->integer('order_qty_pcs')->default(0);
                $t->decimal('mixing_qty', 12, 3)->nullable();
                $t->string('priority', 12)->default('Normal'); // Normal|Urgent|Critical
                $t->boolean('sample_available')->default(false);
                $t->text('sdh_remarks')->nullable();
                $t->text('pdh_remarks')->nullable();
                $t->string('status', 16)->default('Open');     // Open|Planned|In Process|Completed|Closed
                $t->decimal('order_kg', 14, 3)->nullable();    // cached derived
                $t->dateTime('planned_completion')->nullable();
                $t->integer('delay_days')->default(0);

                // process applicability
                $t->boolean('needs_blending')->default(true);
                $t->boolean('needs_extrusion')->default(true);
                $t->boolean('needs_printing')->default(false);
                $t->boolean('needs_cutting')->default(false);

                // Extruder spec
                $t->string('ext_width', 40)->nullable();
                $t->string('ext_gusset', 40)->nullable();
                $t->string('ext_gauge', 40)->nullable();
                $t->string('ext_film_colour', 60)->nullable();
                $t->string('ext_weight_per_roll', 40)->nullable();
                $t->string('ext_type_of_roll', 20)->nullable(); // Sheet|Tube|Roll
                $t->boolean('ext_sample')->default(false);

                // Printing spec
                $t->string('prn_specification')->nullable();
                $t->string('prn_no_colours', 40)->nullable();   // e.g. "0+4"
                $t->string('prn_colours')->nullable();          // e.g. white,yellow,cyan,brown
                $t->string('prn_single_double', 20)->nullable();
                $t->string('prn_direction', 20)->nullable();    // Vertical|Horizontal
                $t->string('prn_gap_top', 40)->nullable();
                $t->string('prn_gap_bottom', 40)->nullable();
                $t->boolean('prn_sample')->default(false);

                // Cutting spec
                $t->string('cut_type', 40)->nullable();         // Side Seal...
                $t->string('cut_bag_size', 60)->nullable();     // 11"x18"x20.5"
                $t->string('cut_sealing', 40)->nullable();
                $t->string('cut_bottom_gusset', 40)->nullable();
                $t->string('cut_handle_punch', 60)->nullable();
                $t->string('cut_handle_position', 60)->nullable();
                $t->string('cut_hole_punch', 60)->nullable();   // 2 holes...
                $t->string('cut_hole_positions', 60)->nullable();
                $t->boolean('cut_sample')->default(false);

                $t->timestamps();
                $t->unique(['tenant_id', 'indent_no']);
                $t->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('ww_indent_blends')) {
            Schema::create('ww_indent_blends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->foreignId('indent_id')->constrained('ww_indents')->cascadeOnDelete();
                $t->integer('line_no')->default(1);
                $t->unsignedBigInteger('material_id')->nullable();
                $t->string('material_name');
                $t->decimal('pct_a', 8, 3)->default(0);  $t->decimal('qty_a', 14, 3)->default(0);
                $t->decimal('pct_b', 8, 3)->default(0);  $t->decimal('qty_b', 14, 3)->default(0);
                $t->decimal('pct_c', 8, 3)->default(0);  $t->decimal('qty_c', 14, 3)->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ww_indent_qc')) {
            Schema::create('ww_indent_qc', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->foreignId('indent_id')->constrained('ww_indents')->cascadeOnDelete();
                $t->string('process', 20);                       // Blending|Extrusion|Printing|Cutting
                $t->dateTime('production_at')->nullable();
                $t->string('supervisor_sign')->nullable();   $t->dateTime('supervisor_at')->nullable();
                $t->string('qc_sign')->nullable();           $t->dateTime('qc_at')->nullable();
                $t->string('sec_head_sign')->nullable();     $t->dateTime('sec_head_at')->nullable();
                $t->string('result', 12)->nullable();            // pass|reject
                $t->timestamps();
                $t->unique(['tenant_id', 'indent_id', 'process']);
            });
        }
    }

    public function down(): void
    {
        foreach (['ww_indent_qc','ww_indent_blends','ww_indents'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
