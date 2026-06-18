<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Win World Phase 3: sales order workflow (SOP) + audit trail of stage events. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_sales_orders')) {
            Schema::create('ww_sales_orders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('order_no', 40);
                $t->unsignedBigInteger('customer_id')->nullable()->index();
                $t->string('customer_name');
                $t->string('contact')->nullable();
                $t->string('source', 16)->nullable();        // visit|whatsapp|email|call
                $t->string('product_name')->nullable();
                $t->integer('qty')->default(0);
                $t->decimal('value', 16, 2)->default(0);
                $t->string('stage', 24)->default('enquiry');
                $t->string('owner_role', 24)->nullable();
                $t->dateTime('stage_started_at')->nullable();
                $t->dateTime('sla_due_at')->nullable();
                $t->string('status', 12)->default('open');   // open|won|lost|on_hold
                $t->integer('overdue_days')->default(0);      // snapshot at credit check
                $t->text('evidence')->nullable();             // note / screenshot link / call note
                $t->string('assigned_to')->nullable();
                $t->unsignedBigInteger('indent_id')->nullable(); // bridge to production indent
                $t->timestamps();
                $t->unique(['tenant_id', 'order_no']);
                $t->index(['tenant_id', 'stage']);
                $t->index(['tenant_id', 'status']);
            });
        }
        if (! Schema::hasTable('ww_sales_events')) {
            Schema::create('ww_sales_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->foreignId('sales_order_id')->constrained('ww_sales_orders')->cascadeOnDelete();
                $t->string('stage', 24)->nullable();
                $t->string('action', 16);     // capture|advance|approve|return|remark|won|lost|hold|reopen
                $t->string('role', 24)->nullable();
                $t->string('actor')->nullable();
                $t->string('note')->nullable();
                $t->dateTime('at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'sales_order_id']);
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('ww_sales_events');
        Schema::dropIfExists('ww_sales_orders');
    }
};
