<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accounting Controls: immutable audit trail + soft-void (never physically delete). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('entity_type', 40);          // e.g. order_cost, period, order
                $t->unsignedBigInteger('entity_id')->nullable();
                $t->string('action', 30);               // create|update|approve|reject|void|lock|unlock|reverse
                $t->string('actor', 120)->nullable();   // display name
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->text('old_values')->nullable();     // JSON snapshot before
                $t->text('new_values')->nullable();     // JSON snapshot after
                $t->text('reason')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['tenant_id','entity_type','entity_id']);
                $t->index(['tenant_id','created_at']);
            });
        }
        if (Schema::hasTable('order_costs')) {
            Schema::table('order_costs', function (Blueprint $t) {
                if (! Schema::hasColumn('order_costs','voided_at'))   $t->timestamp('voided_at')->nullable();
                if (! Schema::hasColumn('order_costs','voided_by'))   $t->string('voided_by', 120)->nullable();
                if (! Schema::hasColumn('order_costs','void_reason')) $t->string('void_reason', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_costs')) {
            Schema::table('order_costs', function (Blueprint $t) {
                foreach (['voided_at','voided_by','void_reason'] as $c)
                    if (Schema::hasColumn('order_costs',$c)) $t->dropColumn($c);
            });
        }
        Schema::dropIfExists('audit_logs');
    }
};
