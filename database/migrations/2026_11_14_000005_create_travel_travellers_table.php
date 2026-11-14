<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel enquiry (issue #145): the travellers on a trip, read from the machine-readable zone of the
 * passport or ID card the customer sent. The image itself is never stored anywhere.
 *
 * The document number is stored ENCRYPTED with the application key (`document_number`), with only its
 * last four characters in clear for display, and a keyed hash (`fingerprint`) so the same document
 * sent twice updates its row instead of adding a second traveller.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('travel_travellers')) {
            return;
        }
        Schema::create('travel_travellers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('enquiry_id')->index();
            $t->string('source', 16)->default('mrz');            // how it was learnt: mrz
            $t->string('mrz_format', 4)->nullable();              // TD1 | TD2 | TD3
            $t->string('document_type', 2)->nullable();           // P passport, I/A/C identity card, V visa
            $t->string('issuing_country', 3)->nullable();         // ICAO three-letter code
            $t->string('surname', 80);
            $t->string('given_names', 120)->nullable();
            $t->string('nationality', 3)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('sex', 1)->nullable();                     // M | F | X
            $t->date('expiry_date')->nullable();
            $t->text('document_number')->nullable();              // encrypted (Laravel "encrypted" cast)
            $t->string('document_last4', 4)->nullable();          // for display: "…902C3" style masking
            $t->string('fingerprint', 64);                        // HMAC of type + country + number
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->unique(['enquiry_id', 'fingerprint'], 'travel_traveller_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_travellers');
    }
};
