<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Learning loop: log every clarification so repeated query→choice patterns become alias proposals. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('resolution_clarifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->nullable()->index();
            $t->string('query');
            $t->jsonb('candidates_json')->nullable();
            $t->unsignedBigInteger('chosen_product_id')->nullable();
            $t->text('customer_reply')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'query']);
        });
    }
    public function down(): void { Schema::dropIfExists('resolution_clarifications'); }
};
