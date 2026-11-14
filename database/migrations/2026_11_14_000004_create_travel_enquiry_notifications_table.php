<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel enquiry (issue #145): one row per staff alert attempted for an enquiry, so a failed alert is
 * visible and can be retried, and the same enquiry is never sent twice to the same person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('travel_enquiry_notifications')) {
            return;
        }
        Schema::create('travel_enquiry_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('enquiry_id')->index();
            $t->string('recipient', 20);                  // a staff WhatsApp number, digits only
            $t->string('kind', 12)->default('new');       // new | updated
            $t->string('state_hash', 40);                 // the enquiry content this alert carries
            $t->string('status', 12)->default('pending'); // pending | sent | failed
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('last_error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->unique(['enquiry_id', 'recipient', 'state_hash'], 'travel_notif_once');
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_enquiry_notifications');
    }
};
