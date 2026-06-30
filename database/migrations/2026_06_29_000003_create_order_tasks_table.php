<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Task Engine. Each order has exactly ONE active task at a time (status in
 * pending|in_progress|exception|paused). Completing it opens the next stage's task.
 * Ownership resolves through a role, so reassigning the role reroutes future tasks.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_tasks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('order_id')->index();
            $t->unsignedSmallInteger('seq')->default(0);     // stage order index
            $t->string('stage');                              // stage key
            $t->string('role');                               // responsible role key
            $t->unsignedBigInteger('owner_user_id')->nullable()->index();
            $t->string('title');
            $t->string('status')->default('pending');         // pending|in_progress|exception|paused|completed|skipped
            $t->unsignedInteger('sla_minutes')->default(0);    // snapshot at assignment
            $t->unsignedInteger('paused_minutes')->default(0); // accumulated pause (excluded from SLA)

            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('due_at')->nullable()->index();
            $t->timestamp('paused_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();

            $t->string('exception_type')->nullable();          // out_of_stock | portal_down | missing_doc | other
            $t->text('exception_reason')->nullable();
            $t->boolean('requires_upload')->default(false);
            $t->string('attachment')->nullable();
            $t->text('note')->nullable();
            $t->boolean('escalated')->default(false);
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'role', 'status']);
            $t->index(['order_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('order_tasks'); }
};
