<?php declare(strict_types=1);

/**
 * FULL BOOKS — both sides + reporting. Records Patronics sales AND purchases through the kernel,
 * then proves the trial balance balances and the P&L / balance-sheet roll-ups are correct.
 *
 * Run: CLOUDBSS_PG_DSN="pgsql:host=/tmp;port=5433;dbname=cloudbss" php infra/tests/books_test.php
 */

spl_autoload_register(function (string $class): void {
    $roots = [
        'CloudBSS\\Kernel\\' => __DIR__ . '/../../src/',
        'CloudBSS\\App\\'    => __DIR__ . '/../../app/',
        'CloudBSS\\Infra\\'  => __DIR__ . '/../',
    ];
    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});

use CloudBSS\App\{UgandaVatSalesPolicy, UgandaVatPurchasePolicy};
use CloudBSS\Infra\{LedgerPoster, SalesLedger, PurchaseLedger};
use CloudBSS\Infra\Postgres\{PostgresEventLog, PostgresLedgerProjection};
use CloudBSS\Infra\Reporting\TrialBalance;
use CloudBSS\Kernel\Ledger\RegistryAccountingPolicyResolver;
use CloudBSS\Kernel\Support\{SystemClock, RandomIdGenerator};

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass,$fail; if($c){$pass++; echo "  \033[32m✓\033[0m {$l}\n";} else {$fail++; echo "  \033[31m✗ {$l}\033[0m\n";} }
function section(string $s): void { echo "\n\033[1m{$s}\033[0m\n"; }

$dsn = getenv('CLOUDBSS_PG_DSN') ?: 'pgsql:host=/tmp;port=5433;dbname=cloudbss';
$pdo = new \PDO($dsn, getenv('CLOUDBSS_PG_USER') ?: 'postgres', getenv('CLOUDBSS_PG_PASS') ?: '');
$log = new PostgresEventLog($pdo);
$log->migrate();
$pdo->exec('TRUNCATE cloudbss_events RESTART IDENTITY; TRUNCATE cloudbss_ledger_balances;');

// Both policies registered for Patronics (t_ug / e1).
$poster = (function () use ($pdo) {
    $clock = new SystemClock(); $ids = new RandomIdGenerator();
    $resolver = new RegistryAccountingPolicyResolver();
    $resolver->register('t_ug', 'e1', new UgandaVatSalesPolicy($clock, $ids));
    // NOTE: a RegistryAccountingPolicyResolver maps ONE policy per (tenant|entity). Because sales
    // and purchases are distinct event names, we compose them so both fire — see CompositePolicy.
    return new LedgerPoster($pdo, new PostgresEventLog($pdo), new PostgresLedgerProjection($pdo), $clock, $ids,
        new class($clock, $ids) implements \CloudBSS\Kernel\Ledger\AccountingPolicyResolver {
            private array $ps;
            public function __construct($clock, $ids) {
                $this->ps = [new UgandaVatSalesPolicy($clock, $ids), new UgandaVatPurchasePolicy($clock, $ids)];
            }
            public function policyFor(\CloudBSS\Kernel\Event\DomainEvent $e): ?\CloudBSS\Kernel\Ledger\AccountingPolicy {
                foreach ($this->ps as $p) if ($p->supports($e)) return $p;
                return null;
            }
        });
})();

$sales     = new SalesLedger($poster);
$purchases = new PurchaseLedger($poster);
$report    = new TrialBalance($pdo);

// =====================================================================================
section('Record the month: 2 sales + 1 purchase (Patronics, UGX)');
ok('sale-001 net 100,000',      $sales->recordSale('t_ug','e1','sale-001', 100_000, 60_000));
ok('sale-002 net 200,000',      $sales->recordSale('t_ug','e1','sale-002', 200_000, 90_000));
ok('purchase-001 net 500,000',  $purchases->recordPurchase('t_ug','e1','purchase-001', 500_000));

// =====================================================================================
section('Trial balance');
$tb = $report->trialBalance('t_ug','e1','UGX');
foreach ($tb['rows'] as $r) {
    printf("    %-5s %-24s %-9s  Dr %12s  Cr %12s\n",
        $r['account'], substr($r['name'],0,24), $r['type'],
        number_format($r['debit']), number_format($r['credit']));
}
printf("    %-40s Dr %12s  Cr %12s\n", 'TOTALS', number_format($tb['total_debit']), number_format($tb['total_credit']));
ok('total debits == total credits', $tb['balanced']);
// Expected balances:
//  Sales: AR 1100 = 118,000+236,000 = 354,000 Dr ; VAT out 2200 = 54,000 Cr ; Sales 4000 = 300,000 Cr
//         COGS 5000 = 150,000 Dr ; Inventory 1200 = -150,000 (credit from COGS) + 500,000 (purchase) = 350,000 Dr
//  Purchase: Inventory 1200 +500,000 ; VAT in 1300 = 90,000 Dr ; AP 2000 = 590,000 Cr
$find = fn(string $acc) => array_values(array_filter($tb['rows'], fn($r)=>$r['account']===$acc))[0] ?? null;
ok('AR (1100) Dr 354,000',        ($find('1100')['debit']  ?? 0) === 354000);
ok('VAT output (2200) Cr 54,000', ($find('2200')['credit'] ?? 0) === 54000);
ok('VAT input (1300) Dr 90,000',  ($find('1300')['debit']  ?? 0) === 90000);
ok('AP (2000) Cr 590,000',        ($find('2000')['credit'] ?? 0) === 590000);
ok('Inventory (1200) Dr 350,000 (500k in − 150k COGS out)', ($find('1200')['debit'] ?? 0) === 350000);
ok('Sales (4000) Cr 300,000',     ($find('4000')['credit'] ?? 0) === 300000);
ok('COGS (5000) Dr 150,000',      ($find('5000')['debit']  ?? 0) === 150000);

// =====================================================================================
section('P&L and balance-sheet roll-ups');
$pl = $report->profitAndLoss('t_ug','e1','UGX');
printf("    income %s − expense %s = net profit %s\n",
    number_format($pl['income']), number_format($pl['expense']), number_format($pl['net_profit']));
ok('income = 300,000',            $pl['income'] === 300000);
ok('expense (COGS) = 150,000',    $pl['expense'] === 150000);
ok('net profit = 150,000',        $pl['net_profit'] === 150000);

$bs = $report->balanceSheetTotals('t_ug','e1','UGX');
printf("    assets %s | liabilities %s | equity %s\n",
    number_format($bs['asset']), number_format($bs['liability']), number_format($bs['equity']));
// assets: AR 354,000 + Inventory 350,000 + VAT input 90,000 = 794,000
// liabilities: VAT output 54,000 + AP 590,000 = 644,000
// assets − liabilities = 150,000 = retained profit (equity not yet booked) → matches net profit
ok('assets = 794,000',            $bs['asset'] === 794000);
ok('liabilities = 644,000',       $bs['liability'] === 644000);
ok('assets − liabilities = net profit 150,000', ($bs['asset'] - $bs['liability']) === $pl['net_profit']);

// =====================================================================================
section('Idempotency across both ledgers');
ok('re-post sale-001 → no-op',      $sales->recordSale('t_ug','e1','sale-001', 100_000, 60_000) === false);
ok('re-post purchase-001 → no-op',  $purchases->recordPurchase('t_ug','e1','purchase-001', 500_000) === false);
ok('book still balances',           $report->trialBalance('t_ug','e1','UGX')['balanced']);

echo "\n\033[1m" . ($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} passed, {$fail} failed"
   . ($fail === 0 ? " — FULL BOOKS + TRIAL BALANCE PROVEN" : " — FAILURES") . "\033[0m\n";
exit($fail === 0 ? 0 : 1);
