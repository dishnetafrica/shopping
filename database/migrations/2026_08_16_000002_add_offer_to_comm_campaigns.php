<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — product-aware campaigns. A campaign can name what it is PROMOTING (product ids, a category,
 * a promo line / link) separately from WHO it targets (that stays in `segment`). The offer feeds the
 * template variables ({{product_name}}, {{price}}, {{category}}) and the preview.
 *
 * Additive nullable json, so every existing campaign is unchanged and nothing reads it until an offer
 * is set. Audience predicates are NOT stored here — they remain in `segment`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('comm_campaigns') && ! Schema::hasColumn('comm_campaigns', 'offer')) {
            Schema::table('comm_campaigns', function (Blueprint $t) {
                $t->json('offer')->nullable()->after('segment');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('comm_campaigns') && Schema::hasColumn('comm_campaigns', 'offer')) {
            Schema::table('comm_campaigns', fn (Blueprint $t) => $t->dropColumn('offer'));
        }
    }
};
