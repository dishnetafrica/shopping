<?php declare(strict_types=1);

namespace App\Reports;

use App\Reports\Definitions\CashPositionReport;
use App\Reports\Definitions\ProfitAndLossReport;
use App\Reports\Definitions\SalesReport;
use App\Reports\Definitions\StockOnHandReport;
use App\Reports\Definitions\TrialBalanceReport;

/**
 * The registry of report definitions (Roadmap E7.1). The generic report API (E7.2) resolves a report
 * by key from here, so adding a report is one register() call — no new endpoint. `default()` returns
 * the built-in set.
 */
class ReportRegistry
{
    /** @var array<string,ReportDefinition> */
    private array $defs = [];

    public function register(ReportDefinition $d): void
    {
        $this->defs[$d->key()] = $d;
    }

    public function get(string $key): ?ReportDefinition
    {
        return $this->defs[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->defs[$key]);
    }

    /** @return ReportDefinition[] */
    public function all(): array
    {
        return array_values($this->defs);
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->defs);
    }

    /** The built-in report set. */
    public static function default(): self
    {
        $r = new self();
        $r->register(new CashPositionReport());
        $r->register(new StockOnHandReport());
        $r->register(new SalesReport());
        $r->register(new ProfitAndLossReport());
        $r->register(new TrialBalanceReport());
        return $r;
    }
}
