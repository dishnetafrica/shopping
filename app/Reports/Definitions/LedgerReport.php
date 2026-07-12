<?php declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Models\Tenant;
use App\Reports\AbstractReport;

/**
 * Base for reports that read the EXISTING (frozen) accounting ledger (Roadmap E7.4). Accounting is
 * frozen — these reports never post or change a journal; they only expose the trial balance / P&L the
 * ledger already produces (CloudBSS\Infra\Reporting\TrialBalance) through the E7 report contract, so
 * the numbers match the Books screen exactly. The ledger scope + minor-unit factor mirror
 * BooksController::scope so the figures are identical.
 */
abstract class LedgerReport extends AbstractReport
{
    /** Whether the ledger is enabled for this tenant (Books is gated on ledger_enabled). */
    protected function ledgerEnabled(Tenant $t): bool
    {
        return (bool) $t->setting('ledger_enabled', false);
    }

    /** @return array{0:string,1:string,2:string,3:int} [tenantKey, entity, currency, minorFactor] */
    protected function scope(Tenant $t): array
    {
        $tid = (string) $t->setting('ledger_tenant', 't_' . $t->id);
        $eid = (string) $t->setting('ledger_entity', 'e1');
        $cur = (string) $t->setting('currency', 'UGX');
        try {
            $factor = (int) \CloudBSS\Kernel\Money\Currency::of($cur)->subunit();
        } catch (\Throwable $e) {
            $factor = 1;
        }
        if ($factor < 1) $factor = 1;
        return [$tid, $eid, $cur, $factor];
    }

    /** Convert a minor-unit integer to a major-unit float. */
    protected function major(int $minor, int $factor): float
    {
        return round($minor / $factor, 2);
    }
}
