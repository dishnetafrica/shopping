<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Trust Engine v1 (Spec v1.0) — the seven trust tables, ADDITIVE ONLY.
 *
 * Scores live against the existing append-only stock_movements ledger (E6.1); nothing here touches
 * existing tables or behaviour. v1 is SILENT-MODE scoring (Pilot Plan Phase B0): scores are computed
 * and stored, no gates block any posting. Signal weights live in code (Services\Trust\SignalCatalog)
 * for determinism; trust_signal_definitions mirrors them for visibility and future runtime override.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('trust_signal_definitions')) {
            Schema::create('trust_signal_definitions', function (Blueprint $t) {
                $t->id();
                $t->string('code', 60)->unique();
                $t->char('independence_class', 1);          // S | P | A | X
                $t->decimal('logit_present', 5, 2);
                $t->decimal('logit_missing', 5, 2)->default(0);
                $t->json('applies_to');                     // ['IN','OUT','MOVE','ADJUST']
                $t->unsignedSmallInteger('version')->default(1);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('trust_evidence_items')) {
            Schema::create('trust_evidence_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('stock_movement_id')->nullable()->index();
                $t->string('signal_code', 60);
                $t->string('status', 20);                   // present_pass|present_fail|expected_missing|na
                $t->json('payload')->nullable();
                $t->char('payload_sha256', 64)->nullable();
                $t->string('captured_by')->nullable();      // null = passive/system source
                $t->timestamp('captured_at')->useCurrent();
                $t->timestamps();
                $t->index(['tenant_id', 'signal_code']);
            });
        }

        if (! Schema::hasTable('trust_scores')) {
            Schema::create('trust_scores', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('stock_movement_id')->unique();
                $t->unsignedSmallInteger('score_version')->default(1);
                $t->decimal('logit_base', 6, 3);
                $t->decimal('logit_prior', 6, 3);
                $t->decimal('logit_evidence', 6, 3);
                $t->decimal('trust', 5, 2);                 // 0–100
                $t->decimal('trust_required', 5, 2);
                $t->decimal('value_at_risk', 14, 2)->default(0);
                $t->decimal('expected_loss', 14, 2)->default(0);
                $t->string('decision', 12);                 // auto_post|step_up|hold (informational in v1)
                $t->json('reason_codes');
                $t->timestamps();
                $t->index(['tenant_id', 'expected_loss']);
            });
        }

        if (! Schema::hasTable('trust_entity_stats')) {
            Schema::create('trust_entity_stats', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('entity_type', 20);              // actor|supplier|category
                $t->string('entity_key', 120);              // created_by string / supplier id / category
                $t->string('txn_type', 12)->default('*');
                $t->decimal('alpha', 12, 3)->default(0);
                $t->decimal('beta', 12, 3)->default(0);
                $t->decimal('exposure_value', 16, 2)->default(0);
                $t->unsignedInteger('exposure_count')->default(0);
                $t->timestamps();
                $t->unique(['tenant_id', 'entity_type', 'entity_key', 'txn_type'], 'trust_entity_unique');
            });
        }

        if (! Schema::hasTable('trust_record_confidence')) {
            Schema::create('trust_record_confidence', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id');
                $t->string('location', 40);
                $t->decimal('confidence', 4, 3)->default(0);
                $t->timestamp('last_counted_at')->nullable();
                $t->decimal('unverified_value', 16, 2)->default(0);
                $t->timestamps();
                $t->unique(['tenant_id', 'product_id', 'location'], 'trust_recconf_unique');
            });
        }

        if (! Schema::hasTable('trust_investigations')) {
            Schema::create('trust_investigations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('stock_movement_id')->index();
                $t->string('source', 20);                   // queue|random|manual|count_variance|backtest
                $t->decimal('expected_loss', 14, 2)->default(0);
                $t->string('outcome', 20)->nullable();      // clean|error|confirmed_loss|unresolved
                $t->decimal('outcome_value', 14, 2)->nullable();
                $t->string('resolved_by')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('trust_variance_events')) {
            Schema::create('trust_variance_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id')->nullable();
                $t->string('sku')->nullable();
                $t->string('location', 40);
                $t->decimal('variance_qty', 14, 3);
                $t->decimal('variance_value', 14, 2)->default(0);
                $t->foreignId('adjust_movement_id')->nullable();   // the ADJUST that revealed it
                $t->timestamp('window_start')->nullable();
                $t->timestamp('window_end')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'sku', 'location']);
            });
        }

        if (! Schema::hasTable('vision_events')) {
            Schema::create('vision_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('event', 60);                    // shelf.out_of_stock etc. (Gateway M1 schema)
                $t->string('store', 80)->nullable();
                $t->string('camera', 120)->nullable();
                $t->string('zone', 120)->nullable();
                $t->string('from_state', 20)->nullable();
                $t->string('to_state', 20)->nullable();
                $t->decimal('fill', 5, 4)->nullable();
                $t->json('payload')->nullable();            // full raw event (thumbnail stripped)
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'zone']);
            });
        }
    }

    public function down(): void
    {
        foreach (['vision_events', 'trust_variance_events', 'trust_investigations',
                  'trust_record_confidence', 'trust_entity_stats', 'trust_scores',
                  'trust_evidence_items', 'trust_signal_definitions'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
