<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Draft Order Timeline: every update is an append-only event (debugging, support, analytics). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_draft_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->index();
            $t->unsignedBigInteger('draft_id')->nullable()->index();
            $t->string('field');                          // line | address | delivery_time | ...
            $t->jsonb('value_json')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void { Schema::dropIfExists('order_draft_events'); }
};
