<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp marketing broadcast — explicit consent + per-recipient tracking.
 *
 * Extends the Communication Center (System B) rather than adding a new engine. Two additive,
 * reversible changes:
 *
 * 1. customer_profiles — EXPLICIT marketing consent. A prior inbound WhatsApp message is NOT consent,
 *    so these start NULL for every existing customer (0 opted-in → 0 broadcasts, by design). Consent
 *    is captured later, explicitly, through the bot/checkout/panel. Opt-OUT enforcement stays in
 *    comm_suppressions (the do-not-contact list the send path already checks); the opted_out_* columns
 *    here are the written-together audit/display copy, never the sole enforcement source.
 *
 * 2. comm_messages — the per-recipient spine gains `replied_at` (WhatsApp reply attribution; "read"
 *    reuses the existing `opened_at`) and `skip_reason` (why a candidate was not sent — recorded, per
 *    requirement 7). A new `skipped` status value is a string, so no schema change is needed for it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('customer_profiles')) {
            Schema::table('customer_profiles', function (Blueprint $t) {
                if (! Schema::hasColumn('customer_profiles', 'marketing_opted_in_at')) {
                    $t->timestamp('marketing_opted_in_at')->nullable()->after('last_whatsapp_at');
                }
                if (! Schema::hasColumn('customer_profiles', 'marketing_opt_in_source')) {
                    $t->string('marketing_opt_in_source', 40)->nullable()->after('marketing_opted_in_at');
                }
                if (! Schema::hasColumn('customer_profiles', 'marketing_opted_out_at')) {
                    $t->timestamp('marketing_opted_out_at')->nullable()->after('marketing_opt_in_source');
                }
                if (! Schema::hasColumn('customer_profiles', 'marketing_opt_out_source')) {
                    $t->string('marketing_opt_out_source', 40)->nullable()->after('marketing_opted_out_at');
                }
            });
            // Index the eligibility predicate (opted-in, not opted-out) that every audience query uses.
            $this->addIndex('customer_profiles', ['tenant_id', 'marketing_opted_in_at'], 'cp_marketing_optin_idx');
        }

        if (Schema::hasTable('comm_messages')) {
            Schema::table('comm_messages', function (Blueprint $t) {
                if (! Schema::hasColumn('comm_messages', 'replied_at')) {
                    $t->timestamp('replied_at')->nullable()->after('opened_at');
                }
                if (! Schema::hasColumn('comm_messages', 'skip_reason')) {
                    $t->string('skip_reason', 40)->nullable()->after('last_error');
                }
            });
            // Frequency guard + reply attribution both look up recent sends by (tenant, recipient).
            $this->addIndex('comm_messages', ['tenant_id', 'to_email'], 'cm_tenant_recipient_idx');
        }
    }

    public function down(): void
    {
        $this->dropIndex('customer_profiles', 'cp_marketing_optin_idx');
        $this->dropIndex('comm_messages', 'cm_tenant_recipient_idx');

        if (Schema::hasTable('customer_profiles')) {
            Schema::table('customer_profiles', function (Blueprint $t) {
                foreach (['marketing_opted_in_at', 'marketing_opt_in_source', 'marketing_opted_out_at', 'marketing_opt_out_source'] as $col) {
                    if (Schema::hasColumn('customer_profiles', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('comm_messages')) {
            Schema::table('comm_messages', function (Blueprint $t) {
                foreach (['replied_at', 'skip_reason'] as $col) {
                    if (Schema::hasColumn('comm_messages', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }

    /** Add a composite index only if the table lacks one by this name (idempotent across re-runs). */
    private function addIndex(string $table, array $cols, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $exists = collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $name);
        if (! $exists) {
            Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $exists = collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $name);
        if ($exists) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }
};
