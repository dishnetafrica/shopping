<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Advisor — store the generated proposal on a captured project (Slice 3).
 *
 * `proposal` holds the assembled, sectioned proposal document (jsonb). `quotation_id` optionally links
 * the proposal to a Quotation created through the existing quote engine when the offer is sent. Additive
 * and reversible; safe whether or not the Slice 2 table migration has already run.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('advisor_projects')) {
            return;
        }
        Schema::table('advisor_projects', function (Blueprint $t) {
            if (! Schema::hasColumn('advisor_projects', 'proposal')) {
                $t->jsonb('proposal')->nullable()->after('sizing');
            }
            if (! Schema::hasColumn('advisor_projects', 'quotation_id')) {
                $t->unsignedBigInteger('quotation_id')->nullable()->after('proposal');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('advisor_projects')) {
            return;
        }
        Schema::table('advisor_projects', function (Blueprint $t) {
            foreach (['proposal', 'quotation_id'] as $c) {
                if (Schema::hasColumn('advisor_projects', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
