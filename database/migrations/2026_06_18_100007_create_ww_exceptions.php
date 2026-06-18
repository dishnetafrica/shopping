<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Win World Phase 3: SOP exceptions — complaints, goods returns, credit/debit notes. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ww_exceptions')) return;
        Schema::create('ww_exceptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('ref', 40);
            $t->string('type', 16);                 // complaint|goods_return|credit_note|debit_note
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name');
            $t->unsignedBigInteger('sales_order_id')->nullable();
            $t->string('subject');
            $t->decimal('amount', 16, 2)->default(0);
            $t->string('status', 12)->default('open'); // open|approved|resolved|rejected
            $t->string('owner_role', 24)->nullable();
            $t->dateTime('stage_started_at')->nullable();
            $t->dateTime('sla_due_at')->nullable();
            $t->dateTime('sla_alerted_at')->nullable();
            $t->string('sm_by')->nullable();  $t->dateTime('sm_at')->nullable();
            $t->string('md_by')->nullable();  $t->dateTime('md_at')->nullable();
            $t->text('resolution')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'ref']);
            $t->index(['tenant_id', 'type']);
            $t->index(['tenant_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('ww_exceptions'); }
};
