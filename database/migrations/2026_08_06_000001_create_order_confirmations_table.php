<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.43 — the order confirmation the customer can be held to.
 *
 * Until now the only thing a customer received when an order was confirmed was the order
 * number: "Your order ORD-1041 is confirmed." Nothing in that message can be disputed
 * because nothing in it is a fact. The itemised numbers reached them on the invoice, which
 * auto-issues ON DELIVERY — after the argument at the truck has already happened.
 *
 * WHY THIS IS A TABLE AND NOT TWO COLUMNS ON `orders`
 *
 *   snapshot     what the customer was actually shown — lines, quantities, unit prices,
 *                total, delivery address, terms. Read from the order at send time and then
 *                FROZEN. The live order keeps moving; the promise does not.
 *   fingerprint  sha256 over the canonical snapshot. This is the whole feature: if anybody
 *                edits the order after the customer agreed, the recomputed fingerprint no
 *                longer matches and the confirmation is known to be stale. A confirmation
 *                you can silently edit is worth nothing in an argument.
 *   message_text the exact words that were sent. When a customer says "I never got that",
 *                the answer is a verbatim copy, not a re-render from today's data.
 *   confirmed_*  when they accepted, how (tapped the link / replied on WhatsApp / staff
 *                recorded it), and from where.
 *
 * One row per DISTINCT version of an order (unique on tenant+order+fingerprint), so re-saving
 * an unchanged order never re-sends, and a genuinely changed order gets its own confirmation
 * instead of quietly overwriting the one the customer already agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_confirmations')) {
            return;
        }
        Schema::create('order_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('order_no', 40)->nullable();

            // The unguessable key to the public confirm page. Deliberately NOT the order's
            // track_token: revoking or rotating a confirmation link must never break tracking.
            $table->string('token', 64)->unique();

            $table->json('snapshot')->nullable();
            $table->string('fingerprint', 64);
            $table->text('message_text')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->string('send_error', 200)->nullable();
            $table->string('trigger', 32)->nullable();      // confirmed | quote_converted | manual

            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_via', 16)->nullable(); // link | whatsapp | staff
            $table->string('confirmed_ip', 45)->nullable();
            $table->string('confirmed_by', 120)->nullable(); // staff name when via=staff
            $table->text('confirmed_note')->nullable();      // the customer's own words, when they replied

            // Stamped when a LATER confirmation supersedes this one (the order changed).
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'confirmed_at']);
            $table->unique(['tenant_id', 'order_id', 'fingerprint'], 'order_conf_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_confirmations');
    }
};
