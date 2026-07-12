<?php declare(strict_types=1);

namespace App\Dashboard;

use App\Models\Tenant;

/**
 * A dashboard widget (Roadmap E10.1, Constitution R4). A widget declares its identity and type and
 * computes its value by reading ONLY SSOT services/projections (CashService, StockLedger, the report
 * definitions, Invoice/CustomerPayment models) — never a bespoke recomputation — so every home
 * screen shows the SAME numbers as the rest of the app. E10.2 renders these behind dash_v2.
 */
interface DashboardWidget
{
    /** Stable unique key, e.g. 'cash_on_hand'. */
    public function key(): string;

    /** Human title. */
    public function title(): string;

    /** Presentation hint: 'stat' | 'stat_multi' | 'list'. */
    public function type(): string;

    /**
     * Compute the widget's value from SSOT for this tenant + user (some widgets scope to the user,
     * e.g. a cashier sees their own cash).
     *
     * @return array<string,mixed>
     */
    public function value(Tenant $t, $user): array;
}
