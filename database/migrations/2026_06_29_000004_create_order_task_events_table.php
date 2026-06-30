<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Immutable audit trail — every assignment, completion, exception, pause, escalation.
 *  This is the order's timeline; nothing is ever deleted or overwritten. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_task_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('order_id')->index();
            $t->unsignedBigInteger('task_id')->nullable()->index();
            $t->string('event');               // created|started|completed|exception|paused|resumed|reassigned|escalated|note
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('from_stage')->nullable();
            $t->string('to_stage')->nullable();
            $t->text('detail')->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void { Schema::dropIfExists('order_task_events'); }
};
