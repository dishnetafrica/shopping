<?php declare(strict_types=1);

namespace App\Reports;

use App\Models\Tenant;

/**
 * The Report definition contract (Roadmap E7.1, Constitution R4 — reports recompute from published
 * read-models, never source tables).
 *
 * A report declares its identity, columns and filters, and produces rows by reading ONLY a
 * single-source-of-truth service or projection (CashService, StockLedger, InvoiceService, Fx, …) —
 * never a raw table. This keeps every report consistent with the rest of the app: one number, one
 * rule. E7.2 builds a generic GET /papi/reports/{key} over these definitions; E7.3/E7.4 add views.
 */
interface ReportDefinition
{
    /** Stable unique key, e.g. 'cash_position'. */
    public function key(): string;

    /** Human title, e.g. 'Cash Position'. */
    public function title(): string;

    /** @return ReportColumn[] */
    public function columns(): array;

    /** @return ReportFilter[] */
    public function filters(): array;

    /**
     * Produce the rows for this report by reading ONLY SSOT services/projections.
     *
     * @param  array<string,mixed>  $filters  validated filter values keyed by filter key
     * @return array<int,array<string,mixed>>  rows keyed by column key
     */
    public function rows(Tenant $t, array $filters = []): array;
}
