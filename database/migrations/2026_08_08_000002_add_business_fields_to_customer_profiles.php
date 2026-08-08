<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.60 — ADR-016 decision 1: extend `customer_profiles`, do not create a `Business` table.
 *
 * Every column is additive and nullable, and there is NO backfill. The existing 676 profiles read
 * `source = null`, `discovered_at = null`, and keep whatever `stage` they already have — so no
 * count, screen, statement, campaign or bot flow can read differently the moment this runs.
 *
 * The index is the one part that is not merely additive in spirit: from this release every customer
 * count carries `coalesce(upper(stage),'') <> 'DISCOVERED'`, and that predicate is about to run on
 * the sidebar of every page load against a table that discovery is meant to grow by thousands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $t) {
            $jsonb = DB::connection()->getDriverName() === 'pgsql';

            // Where the business is, and what it is — the six the CRM record was missing.
            if (! Schema::hasColumn('customer_profiles', 'website'))       $t->string('website')->nullable();
            if (! Schema::hasColumn('customer_profiles', 'latitude'))      $t->decimal('latitude', 10, 7)->nullable();
            if (! Schema::hasColumn('customer_profiles', 'longitude'))     $t->decimal('longitude', 10, 7)->nullable();

            // How this record came to exist: 'csv', 'places', 'rep', or null for the 676 that were
            // here first. Without it, "where did all these come from" has no answer.
            if (! Schema::hasColumn('customer_profiles', 'source'))        $t->string('source', 40)->nullable();
            if (! Schema::hasColumn('customer_profiles', 'discovered_at')) $t->timestamp('discovered_at')->nullable();

            // A BAND, not a headcount — ADR-016 amendment 2. unknown|small|medium|large|enterprise.
            // Nobody knows the employee count, and a made-up number is the same failure as a 94%
            // probability: precision standing in for knowledge. A rep can observe a band on a visit.
            if (! Schema::hasColumn('customer_profiles', 'size'))          $t->string('size', 20)->nullable();

            // Every source's own id — {"google_place":"…","osm":"…"} — so a second provider
            // recognises a business it did not create.
            if (! Schema::hasColumn('customer_profiles', 'external_ids')) {
                $jsonb ? $t->jsonb('external_ids')->nullable() : $t->json('external_ids')->nullable();
            }

            // Per-category detail: {"rooms":82,"star_rating":4}. Named biz_facts and NOT facts,
            // because `facts` already exists on this table and holds the bot's relationship memory.
            // Two different things must never share a column.
            if (! Schema::hasColumn('customer_profiles', 'biz_facts')) {
                $jsonb ? $t->jsonb('biz_facts')->nullable() : $t->json('biz_facts')->nullable();
            }
        });

        // Named explicitly so `down()` can drop it without guessing Laravel's generated name.
        try {
            Schema::table('customer_profiles', function (Blueprint $t) {
                $t->index(['tenant_id', 'stage'], 'customer_profiles_tenant_stage_idx');
            });
        } catch (\Throwable $e) {
            // Already there from a partial run. The columns are what matter; the index is speed.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('customer_profiles', fn (Blueprint $t) => $t->dropIndex('customer_profiles_tenant_stage_idx'));
        } catch (\Throwable $e) {
        }
        Schema::table('customer_profiles', function (Blueprint $t) {
            foreach (['website', 'latitude', 'longitude', 'source', 'discovered_at', 'size', 'external_ids', 'biz_facts'] as $c) {
                if (Schema::hasColumn('customer_profiles', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
