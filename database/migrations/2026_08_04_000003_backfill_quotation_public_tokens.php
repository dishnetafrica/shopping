<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * v4.31.3 — give every OLD quotation a portal token.
 *
 * `public_token` was added by 2026_07_16_000001_add_portal_fields_to_quotations as a nullable
 * column, and the model generates one on `creating` — so every quotation made from that day
 * onward has a token. Nothing ever filled in the ones that already existed.
 *
 * The result was silent and permanent: a third of the quotations on this install (64 of 216)
 * had no token, so their review link could never work, and the revision notifier skips the
 * WhatsApp message entirely when the token is empty — so those customers were not told their
 * quotation had changed, with no error raised anywhere.
 *
 * This fills only rows that have none. An existing token is never rotated: those links are
 * already out with customers and must keep working. Idempotent — a second run does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations') || ! Schema::hasColumn('quotations', 'public_token')) {
            return;
        }

        DB::table('quotations')
            ->where(function ($q) {
                $q->whereNull('public_token')->orWhere('public_token', '');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    // The column carries a UNIQUE index. A 40-char collision is not realistically
                    // possible, but a migration that dies half way through is, so re-draw rather
                    // than let the insert throw.
                    for ($try = 0; $try < 5; $try++) {
                        $token = Str::lower(Str::random(40));
                        if (DB::table('quotations')->where('public_token', $token)->exists()) {
                            continue;
                        }
                        DB::table('quotations')->where('id', $row->id)->update(['public_token' => $token]);
                        break;
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible. Clearing these would break links that are now live with
        // customers, and there is no way to tell a backfilled token from an original one.
    }
};
