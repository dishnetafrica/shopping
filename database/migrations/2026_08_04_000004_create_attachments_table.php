<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.33 — evidence. A receipt photo or supplier invoice attached to any transaction.
 *
 * Until now every uploaded file was a single string column bolted onto its owner row
 * (`order_costs.receipt_path`, `customer_payments.receipt_path`, …) — one file per record,
 * no record of who attached it, and no way to prove the file had not been swapped later.
 * A real supplier purchase needs three documents: their invoice, the delivery note, and
 * the receipt for the money.
 *
 * WHAT MAKES THIS EVIDENCE RATHER THAN JUST A FILE
 *
 *   sha256      the content fingerprint, taken at upload. If the stored bytes ever change,
 *               the hash no longer matches and `evidence:verify` says so. Nothing in this
 *               codebase hashed a stored file before; the two places that used md5 did it
 *               only to build a filename.
 *   uploaded_by who attached it, and when — beside the amount it evidences.
 *   detached_at attachments are NEVER deleted. Removing one records who removed it and why,
 *               exactly like the void-instead-of-delete convention on order_costs. An audit
 *               trail with a hole in it is not an audit trail.
 *
 * Storage is content-addressed: two people photographing the same receipt produce one file
 * on disk and two rows here, so nothing is lost and nothing is stored twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attachments')) {
            return;
        }
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // What it is evidence FOR. Kept as a short slug rather than a PHP class name so a
            // model can be renamed or moved without orphaning years of audit evidence.
            $table->string('subject_type', 40);          // cashbook | purchase | expense | payment | invoice | quotation | order
            $table->unsignedBigInteger('subject_id');

            $table->string('kind', 24)->default('receipt');   // receipt | invoice | delivery_note | voucher | contract | other
            $table->string('path', 512);                 // on the storage disk — never exposed to a browser
            $table->string('original_name', 200);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('sha256', 64)->index();       // content fingerprint — the integrity anchor
            $table->string('note', 300)->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 120)->nullable();   // frozen: the user row may be deleted later
            $table->timestamp('detached_at')->nullable();
            $table->unsignedBigInteger('detached_by')->nullable();
            $table->string('detach_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);   // "show me this entry's documents"
            $table->index(['tenant_id', 'detached_at']);                  // the live set for the evidence report
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
