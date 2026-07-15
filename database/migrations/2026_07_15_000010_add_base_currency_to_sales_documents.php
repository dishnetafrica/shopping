<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base-currency ledger fields for SALES documents (orders, invoices, quotations).
 *
 * Brings the sales side up to the same standard purchases (order_costs) and payments already meet:
 * every financial document stores its TRANSACTION currency, the EXCHANGE RATE used, and the equivalent
 * BASE-currency (USD) amount — so reports consolidate at the historical rate and never re-price history.
 *
 * Additive + reversible: two nullable columns per table. No data is moved here; a separate, dry-run-first
 * backfill command (`sales:backfill-base-currency`) populates them for existing rows once validated.
 */
return new class extends Migration
{
    private array $tables = ['orders', 'invoices', 'quotations'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) { continue; }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'rate_to_usd')) {
                    $t->decimal('rate_to_usd', 16, 6)->nullable()->after('total');   // native units per 1 USD, at doc date
                }
                if (! Schema::hasColumn($table, 'usd_amount')) {
                    $t->decimal('usd_amount', 16, 2)->nullable()->after('rate_to_usd'); // base-currency (USD) equivalent of total
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) { continue; }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'usd_amount')) { $t->dropColumn('usd_amount'); }
                if (Schema::hasColumn($table, 'rate_to_usd')) { $t->dropColumn('rate_to_usd'); }
            });
        }
    }
};
