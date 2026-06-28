<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One persistent draft order per conversation — updated by every customer message. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_drafts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->index();
            $t->jsonb('payload_json')->nullable();        // DraftOrder snapshot
            $t->string('status')->default('open');        // open | confirmed | abandoned
            $t->timestamps();
            $t->unique(['tenant_id', 'conversation_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('order_drafts'); }
};
