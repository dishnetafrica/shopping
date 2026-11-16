<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Brain (DishNet South Sudan sales plan, Phase 3): governed, versioned sales knowledge.
 *
 * sales_knowledge_entries   one row per piece of knowledge per business (e.g. terms / starlink)
 * sales_knowledge_versions  every version ever written, with who wrote, approved, published or
 *                           rejected it and when. Rows are never deleted; an approved version's
 *                           content is never edited (a change is a new version).
 *
 * New tables only. Nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_knowledge_entries')) {
            Schema::create('sales_knowledge_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('topic', 32);
                $t->string('key', 64);
                $t->string('title', 160);
                $t->timestamps();
                $t->unique(['tenant_id', 'topic', 'key'], 'sales_knowledge_entries_unique');
            });
        }
        if (! Schema::hasTable('sales_knowledge_versions')) {
            Schema::create('sales_knowledge_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('entry_id');
                $t->unsignedInteger('version');
                $t->string('status', 16)->default('draft');      // draft | approved | published | retired | rejected
                $t->text('body')->nullable();
                $t->json('data')->nullable();                      // terms items / coverage areas
                $t->string('source', 200)->nullable();             // where the text came from
                $t->json('open_questions')->nullable();            // must be empty before approval
                $t->json('resolved_questions')->nullable();        // [{question, answer, by, at}]
                $t->string('change_note', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('published_by')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->unsignedBigInteger('retired_by')->nullable();
                $t->timestamp('retired_at')->nullable();
                $t->unsignedBigInteger('rejected_by')->nullable();
                $t->timestamp('rejected_at')->nullable();
                $t->string('reject_reason', 500)->nullable();
                $t->timestamps();
                $t->unique(['entry_id', 'version'], 'sales_knowledge_versions_unique');
                $t->index(['tenant_id', 'status'], 'sales_knowledge_versions_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_knowledge_versions');
        Schema::dropIfExists('sales_knowledge_entries');
    }
};
