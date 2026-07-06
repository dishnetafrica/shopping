<?php declare(strict_types=1);

namespace CloudBSS\Infra\Reporting;

/**
 * Reporting read-model over cloudbss_ledger_balances. Pure reads — no writes, no kernel. Gives:
 *   - trialBalance(): every account with its debit/credit column + the totals (which must match),
 *   - profitAndLoss(): income vs expense -> net profit,
 *   - balanceSheetTotals(): asset / liability / equity roll-ups.
 * All scoped by (tenant, entity, currency). Amounts are minor units (debit-positive net internally).
 */
final class TrialBalance
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{rows: list<array{account:string,name:string,type:string,debit:int,credit:int}>,
     *               total_debit:int, total_credit:int, balanced:bool}
     */
    public function trialBalance(string $tenantId, string $entityId, string $currency = 'UGX'): array
    {
        $st = $this->pdo->prepare(
            'SELECT account, account_name, account_type, net_minor
               FROM cloudbss_ledger_balances
              WHERE tenant_id = :t AND entity_id = :en AND currency = :cur
              ORDER BY account'
        );
        $st->execute([':t' => $tenantId, ':en' => $entityId, ':cur' => $currency]);

        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $net = (int) $r['net_minor'];
            if ($net === 0) continue;                 // closed/zero accounts don't clutter the TB
            $debit  = $net > 0 ? $net : 0;            // debit-positive convention
            $credit = $net < 0 ? -$net : 0;
            $totalDebit  += $debit;
            $totalCredit += $credit;
            $rows[] = [
                'account' => (string) $r['account'],
                'name'    => (string) $r['account_name'],
                'type'    => (string) $r['account_type'],
                'debit'   => $debit,
                'credit'  => $credit,
            ];
        }

        return [
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced'     => $totalDebit === $totalCredit,
        ];
    }

    /**
     * @return array{income:int, expense:int, net_profit:int}
     * income/expense are shown positive; net_profit = income - expense.
     */
    public function profitAndLoss(string $tenantId, string $entityId, string $currency = 'UGX'): array
    {
        $byType = $this->sumByType($tenantId, $entityId, $currency);
        $income  = -($byType['income']  ?? 0);   // income accounts carry credit (negative net) -> flip
        $expense =  ($byType['expense'] ?? 0);   // expense accounts carry debit (positive net)
        return ['income' => $income, 'expense' => $expense, 'net_profit' => $income - $expense];
    }

    /**
     * @return array{asset:int, liability:int, equity:int}
     * asset positive (debit), liability/equity positive (credit, flipped for presentation).
     */
    public function balanceSheetTotals(string $tenantId, string $entityId, string $currency = 'UGX'): array
    {
        $byType = $this->sumByType($tenantId, $entityId, $currency);
        return [
            'asset'     =>  ($byType['asset']     ?? 0),
            'liability' => -($byType['liability'] ?? 0),
            'equity'    => -($byType['equity']    ?? 0),
        ];
    }

    /** @return array<string,int> account_type => sum(net_minor) */
    private function sumByType(string $tenantId, string $entityId, string $currency): array
    {
        $st = $this->pdo->prepare(
            'SELECT account_type, COALESCE(SUM(net_minor),0) AS s
               FROM cloudbss_ledger_balances
              WHERE tenant_id = :t AND entity_id = :en AND currency = :cur
              GROUP BY account_type'
        );
        $st->execute([':t' => $tenantId, ':en' => $entityId, ':cur' => $currency]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['account_type']] = (int) $r['s'];
        }
        return $out;
    }
}
