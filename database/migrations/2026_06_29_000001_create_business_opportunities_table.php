<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Business Opportunity is NOT an order. It is a high-value B2B conversation
 * (distributor, dealer, wholesale, export, corporate/government/NGO supply, tender,
 * partnership, franchise, OEM) that the bot qualifies over several turns, scores,
 * sizes, and hands off to the sales team — instead of trying to take a carton order.
 *
 * One ACTIVE opportunity per (tenant, customer_phone). Closed/won/lost rows are kept
 * for history and for the (future) data-driven scoring loop.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_opportunities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('conversation_id')->nullable()->index();
            $t->unsignedBigInteger('lead_id')->nullable()->index();   // set when handed off via LeadService
            $t->string('customer_phone')->index();
            $t->string('customer_name')->nullable();

            $t->string('type')->default('distributor');               // distributor|dealer|wholesale|export|corporate|government|ngo|partnership|franchise|tender
            $t->string('stage')->default('understand');               // understand|qualify|value|handoff|closed
            $t->string('status')->default('qualifying');              // qualifying|qualified|assigned|won|lost

            $t->unsignedSmallInteger('lead_score')->default(0);
            $t->json('score_breakdown')->nullable();                  // ['intent'=>25,'reseller'=>20,...]
            $t->string('size_band')->nullable();                      // small|medium|large|strategic

            $t->json('profile')->nullable();                          // business_name, area, current_brand, buying_price, price_basis, monthly_volume, ...
            $t->json('missing')->nullable();                          // cached unfilled core slots

            $t->string('assigned_to')->nullable();                    // sales rep phone
            $t->timestamp('next_followup_at')->nullable()->index();
            $t->timestamp('last_scored_at')->nullable();
            $t->timestamp('handed_off_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'customer_phone', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('business_opportunities'); }
};
