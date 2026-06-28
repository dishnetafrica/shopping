<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-message order-flow telemetry (router in shadow): what WOULD the Order Engine have done. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_flow_metrics', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->nullable()->index();
            $t->string('intent')->index();              // router intent
            $t->boolean('draft_parsed')->default(false); // would the Draft parser run?
            $t->unsignedSmallInteger('products_found')->default(0);
            $t->boolean('has_address')->default(false);
            $t->boolean('has_time')->default(false);
            $t->boolean('has_payment')->default(false);
            $t->boolean('clarification')->default(false); // did the LIVE bot ask a clarification?
            $t->string('outcome')->nullable();
            $t->string('sample', 200)->nullable();
            $t->unsignedInteger('processing_ms')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['tenant_id', 'intent', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('order_flow_metrics'); }
};
