<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Requested operations extracted from a message; the queue + traceability to a change request. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_actions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('capability')->index();
            $t->string('action_type')->index();                  // set_price|add_menu_item|mark_unavailable|...
            $t->string('target')->nullable();                    // normalized subject (used for collapse)
            $t->jsonb('params_json')->nullable();
            $t->jsonb('entities_json')->nullable();              // [{field,value,confidence}]
            $t->string('source')->default('owner_whatsapp');
            $t->string('status')->default('pending');            // pending|validated|applied|superseded|rejected
            $t->unsignedBigInteger('change_request_id')->nullable();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'capability', 'status']);
            $t->index(['tenant_id', 'action_type', 'target']);
        });
    }
    public function down(): void { Schema::dropIfExists('knowledge_actions'); }
};
