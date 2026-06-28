<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Live-vs-shadow disagreements — the A/B record that drives the review→replay loop. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shadow_diffs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->nullable()->index();
            $t->string('message', 280)->nullable();
            $t->jsonb('live_json')->nullable();
            $t->jsonb('shadow_json')->nullable();
            $t->jsonb('diff_json')->nullable();
            $t->string('reasons')->nullable();              // CSV of reason codes
            $t->boolean('regression')->default(false);      // shadow lost something live had
            $t->string('status')->default('new');           // new|accepted|rejected|bug|promoted
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['tenant_id', 'status', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('shadow_diffs'); }
};
