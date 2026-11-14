<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel Vertical · Milestone 1 — the trip a customer is describing. One open enquiry per
 * conversation; the conversation fills and edits it field by field. `stage` is the travel pipeline
 * (App\Services\Travel\Enquiry\TravelPipeline). Additive and tenant-scoped: no existing table is
 * touched, and nothing reads this table unless the tenant's vertical is `travel`.
 *
 * No passport or identity data is stored here.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('travel_enquiries')) {
            return;
        }
        Schema::create('travel_enquiries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->nullable()->index();
            $t->string('customer_phone', 40)->index();           // "web:{sid}" for website chat
            $t->string('customer_name', 120)->nullable();
            $t->string('channel', 16)->default('whatsapp');      // whatsapp | web
            $t->unsignedBigInteger('lead_id')->nullable()->index();
            $t->unsignedBigInteger('owner_user_id')->nullable()->index();   // staff member responsible

            $t->string('stage', 32)->default('NEW_ENQUIRY');

            // The trip. `segments` is the full record; origin/destination/dates repeat flight 1 for lists.
            $t->string('trip_type', 16)->nullable();             // one_way | return | multi_city
            $t->jsonb('segments')->nullable();
            $t->string('origin')->nullable();
            $t->string('destination')->nullable();
            $t->date('depart_date')->nullable();
            $t->date('return_date')->nullable();
            $t->unsignedSmallInteger('adults')->nullable();
            $t->unsignedSmallInteger('children')->nullable();
            $t->unsignedSmallInteger('infants')->nullable();
            $t->string('cabin', 24)->nullable();
            $t->jsonb('airlines')->nullable();
            $t->string('baggage')->nullable();
            $t->unsignedTinyInteger('flexible_days')->nullable();
            $t->boolean('direct_only')->nullable();
            $t->text('notes')->nullable();
            $t->jsonb('pending')->nullable();                    // open questions (e.g. an ambiguous city)
            $t->boolean('is_group')->default(false);             // 10+ seats: handled by a person

            $t->string('lost_reason')->nullable();
            $t->timestamp('details_completed_at')->nullable();
            $t->timestamp('last_customer_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'stage']);
            $t->index(['tenant_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_enquiries');
    }
};
