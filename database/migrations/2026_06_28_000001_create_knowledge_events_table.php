<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Raw inbound memory: every owner message (any source), logged verbatim + classified intent. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('source')->default('owner_whatsapp');     // Source::*
            $t->string('sender_ref')->nullable();                // phone / user id / channel ref
            $t->text('message');
            $t->string('intent')->nullable()->index();           // Intent::*
            $t->string('capability')->nullable()->index();       // which capability claimed it
            $t->string('status')->default('received');           // received|extracted|queued|applied|failed
            $t->timestamps();
            $t->index(['tenant_id', 'source', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('knowledge_events'); }
};
