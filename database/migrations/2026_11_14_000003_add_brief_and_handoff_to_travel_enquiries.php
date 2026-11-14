<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel enquiry (issue #145): the non-flight part of an enquiry, the customer's confirmation, and
 * where the staff handoff stands. Additive only — new nullable columns on a table that this branch
 * itself introduces; no existing column is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('travel_enquiries')) {
            return;
        }
        Schema::table('travel_enquiries', function (Blueprint $t) {
            if (! Schema::hasColumn('travel_enquiries', 'brief')) {
                $t->jsonb('brief')->nullable();                       // services, destinations, ages, budget, contact (EnquiryBrief)
            }
            if (! Schema::hasColumn('travel_enquiries', 'summary_shown_at')) {
                $t->timestamp('summary_shown_at')->nullable();        // the customer was shown the summary and asked to confirm
            }
            if (! Schema::hasColumn('travel_enquiries', 'submitted_at')) {
                $t->timestamp('submitted_at')->nullable();            // the customer confirmed: the one submission
            }
            if (! Schema::hasColumn('travel_enquiries', 'handoff_status')) {
                $t->string('handoff_status', 16)->nullable();         // sent | partial | failed | no_recipient
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('travel_enquiries')) {
            return;
        }
        Schema::table('travel_enquiries', function (Blueprint $t) {
            foreach (['brief', 'summary_shown_at', 'submitted_at', 'handoff_status'] as $c) {
                if (Schema::hasColumn('travel_enquiries', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
