<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decisions on mined-but-unmatched product candidates. One row per term the owner has
 * approved (→ a draft Product) or dismissed, so the candidate never re-surfaces in the Brain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_product_candidates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('term');                                // display term, e.g. "Starlink"
            $t->string('term_norm', 80);                       // normalised key for de-dupe
            $t->string('decision', 12)->default('approved');   // approved|dismissed
            $t->unsignedBigInteger('product_id')->nullable();  // draft Product created on approve
            $t->string('decided_by')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['tenant_id', 'term_norm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_product_candidates');
    }
};
