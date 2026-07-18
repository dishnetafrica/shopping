<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** V4.1 Goal Engine — every proactive touch is a PROPOSAL first (human-approved until autonomy is earned). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('followup_proposals')) return;
        Schema::create('followup_proposals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('customer_phone', 32)->index();
            $t->string('source', 24)->default('goal_engine');      // goal_engine | guardian (V4.4)
            $t->string('trigger', 32);                              // quote_chase | reorder | winback | promise_due
            $t->string('trigger_reason', 240);                      // the WHY, human-readable
            $t->text('evidence')->nullable();                       // supporting data (quote no, days, promise text)
            $t->text('draft');                                      // the message awaiting approval
            $t->string('confidence', 8)->default('medium');         // low | medium | high (RULE-derived, never AI numbers)
            $t->string('expected_outcome', 160)->nullable();
            $t->string('status', 16)->default('draft')->index();    // draft | sent | skipped | snoozed
            $t->string('skip_reason', 240)->nullable();
            $t->timestamp('snoozed_until')->nullable();
            $t->string('approved_by', 80)->nullable();
            $t->unsignedBigInteger('sent_message_id')->nullable();
            $t->unsignedBigInteger('influenced_order_id')->nullable();   // V4.3 attribution
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('followup_proposals'); }
};
