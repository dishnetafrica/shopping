<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.19 TenderBot — one row per analysed tender opportunity.
 * Guarded (hasTable) like every other CloudBSS migration: safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenders')) {
            return;
        }
        Schema::create('tenders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('source', 20)->default('upload');        // upload | forum
            $t->string('source_ref', 120)->nullable();          // forum topic id/url — dedupe key
            $t->string('organization', 200)->nullable();
            $t->string('tender_no', 120)->nullable();
            $t->string('title', 300)->nullable();
            $t->string('division', 40)->nullable();             // ISP | General Trading | Both | Ignore
            $t->date('deadline')->nullable()->index();
            $t->unsignedTinyInteger('opportunity_score')->nullable();   // 0-100
            $t->string('win_probability', 20)->nullable();
            $t->string('recommendation', 30)->nullable();       // BID | PREQUALIFICATION | REGISTER | PARTNER | IGNORE
            $t->string('status', 20)->default('new')->index();  // new → reviewing → bidding → submitted → won/lost/ignored
            $t->text('summary')->nullable();                    // the WhatsApp executive summary
            $t->json('report')->nullable();                     // the full structured JSON report
            $t->json('files')->nullable();                      // stored attachment paths
            $t->timestamps();
            $t->unique(['tenant_id', 'source', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
