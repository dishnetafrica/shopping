<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable quotation revision ledger — "never overwrite history".
 *
 * Every customer or sales change to a quotation appends one row here. Rows are append-only
 * (enforced in the QuotationRevision model), so the full negotiation history — who changed what,
 * when — is auditable by a single ordered query. The `quotations` row + `quotation_items` remain
 * a fast denormalised projection of the CURRENT revision; this table is the source of truth for
 * the history. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('quotation_id')->index();
            $table->unsignedInteger('rev_no');                 // 1,2,3… monotonic per quotation
            $table->string('actor_type', 16)->default('system'); // customer | sales | system
            $table->unsignedBigInteger('actor_id')->nullable(); // User id when sales
            $table->string('actor_name')->nullable();
            $table->string('status_at', 32)->nullable();        // lifecycle status when this revision was made
            $table->string('currency', 8)->default('UGX');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('freight', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->jsonb('snapshot')->nullable();              // full immutable line/terms/customer state
            $table->jsonb('change_summary')->nullable();        // diff vs previous revision (for the visual "what changed")
            $table->string('pdf_path')->nullable();
            $table->timestamp('created_at')->nullable();        // NO updated_at — rows are never updated
            $table->index(['quotation_id', 'rev_no']);
        });

        // Pointer to the latest revision on the quotation (fast "current revision" without a MAX query).
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedInteger('current_rev')->default(1)->after('sales_exec_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('current_rev');
        });
        Schema::dropIfExists('quotation_revisions');
    }
};
