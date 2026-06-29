<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A concrete next-action a salesperson must take on an opportunity ("Call Usman before
 * 10AM — competitor at 8,500, potential 200 cartons/month"). Powers the Today's
 * Priorities dashboard so sales opens one screen and knows exactly whom to call first.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('opportunity_tasks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('opportunity_id')->index();
            $t->string('title');
            $t->text('reason')->nullable();
            $t->string('assigned_to')->nullable();      // sales rep phone
            $t->unsignedSmallInteger('priority')->default(0);  // mirrors opportunity lead_score for sorting
            $t->string('status')->default('open');      // open|done|skipped
            $t->timestamp('due_at')->nullable()->index();
            $t->timestamp('done_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status', 'due_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('opportunity_tasks'); }
};
