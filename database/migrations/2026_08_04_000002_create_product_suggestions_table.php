<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.29 — the review queue for AI research.
 *
 * One row per (product, field) the pipeline is not confident enough to write on its
 * own. It records what the product holds now, what the source claims, how sure the
 * source is and where it came from — so a human approving it is deciding with the
 * provenance in front of them rather than trusting a number.
 *
 * Note this fills a gap that already existed: `products.product_type_status` has been
 * written as 'needs_review' since v-June by EnrichProductsCommand, and NOTHING in the
 * codebase has ever read or displayed those rows. This table, and the screen that
 * reads it, is where that queue should have lived.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_suggestions')) {
            return;
        }
        Schema::create('product_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);                       // brand | unit_size | origin_country | category | description
            $table->text('current_value')->nullable();         // what the product held when this was raised
            $table->text('suggested_value');
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('source', 20)->default('ai');       // barcode | ai | written
            $table->string('note', 255)->nullable();           // provenance, shown to the reviewer
            $table->string('status', 12)->default('pending');  // pending | applied | dismissed
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);            // the queue screen
            $table->index(['product_id', 'field']);            // de-dupe on re-run
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_suggestions');
    }
};
