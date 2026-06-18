<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Win World CMMS-lite: breakdown log + preventive work orders (MTTR / MTBF / PM compliance). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ww_maint_orders')) return;
        Schema::create('ww_maint_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('ref')->nullable();
            $t->unsignedBigInteger('machine_id')->nullable()->index();
            $t->string('type')->default('breakdown');   // breakdown | preventive
            $t->string('title')->nullable();
            $t->string('priority')->default('Normal');
            $t->string('status')->default('open');       // open | in_progress | done
            $t->timestamp('reported_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('due_at')->nullable();         // for preventive jobs
            $t->integer('downtime_min')->default(0);
            $t->string('reported_by')->nullable();
            $t->string('done_by')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['tenant_id','type','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('ww_maint_orders'); }
};
