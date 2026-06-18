<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Winworld MES - Phase 1 Planning (per process step) + Production Entry (actuals). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_plannings')) {
            Schema::create('ww_plannings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->foreignId('indent_id')->constrained('ww_indents')->cascadeOnDelete();
                $t->string('process', 20);
                $t->unsignedBigInteger('machine_id')->nullable()->index();
                $t->decimal('running_speed', 12, 3)->nullable();
                $t->dateTime('planned_start')->nullable();
                $t->decimal('auto_output_kg_hr', 12, 3)->nullable();   // advisory
                $t->decimal('manual_output_kg_hr', 12, 3)->nullable(); // source of truth
                $t->decimal('final_output_kg_hr', 12, 3)->nullable();
                $t->decimal('required_hours', 10, 3)->nullable();
                $t->dateTime('planned_end')->nullable();
                $t->string('status', 16)->default('Planned');
                $t->string('notes')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'machine_id', 'planned_start']);
            });
        }

        if (! Schema::hasTable('ww_production_entries')) {
            Schema::create('ww_production_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->foreignId('indent_id')->constrained('ww_indents')->cascadeOnDelete();
                $t->unsignedBigInteger('planning_id')->nullable()->index();
                $t->string('process', 20);
                $t->unsignedBigInteger('machine_id')->nullable()->index();
                $t->string('shift', 16)->nullable();
                $t->dateTime('start_time')->nullable();
                $t->dateTime('end_time')->nullable();
                $t->integer('produced_qty_pcs')->default(0);
                $t->decimal('produced_kg', 14, 3)->default(0);
                $t->decimal('scrap_kg', 14, 3)->default(0);
                $t->integer('changeover_min')->default(0);
                $t->decimal('actual_hours', 10, 3)->nullable();
                $t->decimal('actual_output_kg_hr', 12, 3)->nullable();
                $t->decimal('target_output_kg_hr', 12, 3)->nullable();
                $t->decimal('efficiency_pct', 8, 2)->nullable();
                $t->string('qc_result', 12)->nullable();
                $t->string('status', 16)->default('In Process');
                $t->string('stop_reason', 40)->nullable(); // Machine Breakdown|Power Failure|Material Shortage
                $t->string('remarks')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'indent_id']);
                $t->index(['tenant_id', 'machine_id', 'start_time']);
            });
        }
    }

    public function down(): void
    {
        foreach (['ww_production_entries','ww_plannings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
