<?php declare(strict_types=1);

/**
 * PAYMENTS / SETTLEMENT — closes the loop: sales + purchases raise AR/AP, then a customer receipt
 * and a supplier payment settle them. Proves cash moves, AR/AP reduce to the correct outstanding
 * (aged) balances, and the trial balance still balances.
 *
 * Run: CLOUDBSS_PG_DSN="pgsql:host=/tmp;port=5433;dbname=cloudbss" php infra/tests/payments_test.php
 */

spl_autoload_register(function (string $class): void {
    $roots = ['CloudBSS\\Kernel\\'=>__DIR__.'/../../src/','CloudBSS\\App\\'=>__DIR__.'/../../app/','CloudBSS\\Infra\\'=>__DIR__.'/../'];
    foreach ($roots as $p=>$d) { if (str_starts_with($class,$p)) { $f=$d.str_replace('\\','/',substr($class,strlen($p))).'.php'; if(is_file($f)) require $f; return; } }
});

use CloudBSS\App\{UgandaVatSalesPolicy, UgandaVatPurchasePolicy, CashSettlementPolicy, CompositeAccountingPolicy};
use CloudBSS\Infra\{LedgerPoster, SalesLedger, PurchaseLedger, PaymentLedger};
use CloudBSS\Infra\Postgres\{PostgresEventLog, PostgresLedgerProjection};
use CloudBSS\Infra\Reporting\TrialBalance;
use CloudBSS\Kernel\Ledger\RegistryAccountingPolicyResolver;
use CloudBSS\Kernel\Support\{SystemClock, RandomIdGenerator};

$pass=0;$fail=0;
function ok(string $l,bool $c):void{global $pass,$fail; if($c){$pass++;echo "  \033[32m✓\033[0m {$l}\n";}else{$fail++;echo "  \033[31m✗ {$l}\033[0m\n";}}
function section(string $s):void{echo "\n\033[1m{$s}\033[0m\n";}

$dsn=getenv('CLOUDBSS_PG_DSN')?:'pgsql:host=/tmp;port=5433;dbname=cloudbss';
$pdo=new \PDO($dsn, getenv('CLOUDBSS_PG_USER')?:'postgres', getenv('CLOUDBSS_PG_PASS')?:'');
$log=new PostgresEventLog($pdo); $log->migrate();
$pdo->exec('TRUNCATE cloudbss_events RESTART IDENTITY; TRUNCATE cloudbss_ledger_balances;');

$clock=new SystemClock(); $ids=new RandomIdGenerator();
$resolver=new RegistryAccountingPolicyResolver();
$resolver->register('t_ug','e1', new CompositeAccountingPolicy([
    new UgandaVatSalesPolicy($clock,$ids),
    new UgandaVatPurchasePolicy($clock,$ids),
    new CashSettlementPolicy($clock,$ids),
]));
$poster=new LedgerPoster($pdo,new PostgresEventLog($pdo),new PostgresLedgerProjection($pdo),$clock,$ids,$resolver);
$sales=new SalesLedger($poster); $purch=new PurchaseLedger($poster); $pay=new PaymentLedger($poster);
$report=new TrialBalance($pdo);
$B=fn(string $a)=>$poster->balance('t_ug','e1',$a,'UGX')->minor;

section('The month: sales, a purchase, then settlements');
$sales->recordSale('t_ug','e1','sale-001',100_000,60_000);
$sales->recordSale('t_ug','e1','sale-002',200_000,90_000);
$purch->recordPurchase('t_ug','e1','purchase-001',500_000);
ok('AR after sales = 354,000',            $B('1100') === 354000);
ok('AP after purchase = -590,000 (credit)',$B('2000') === -590000);

section('Customer pays 200,000 (Dr Cash, Cr AR)');
ok('receipt recorded',                    $pay->recordCustomerPayment('t_ug','e1','rcpt-001',200_000));
ok('Cash = 200,000',                      $B('1000') === 200000);
ok('AR outstanding = 154,000',            $B('1100') === 154000);

section('We pay a supplier 300,000 (Dr AP, Cr Cash)');
ok('supplier payment recorded',           $pay->recordSupplierPayment('t_ug','e1','pay-001',300_000));
ok('Cash net = -100,000',                 $B('1000') === -100000);
ok('AP outstanding = -290,000 (credit)',  $B('2000') === -290000);

section('Trial balance after settlements');
$tb=$report->trialBalance('t_ug','e1','UGX');
foreach ($tb['rows'] as $r) printf("    %-5s %-22s %-9s Dr %11s Cr %11s\n",$r['account'],substr($r['name'],0,22),$r['type'],number_format($r['debit']),number_format($r['credit']));
printf("    %-38s Dr %11s Cr %11s\n",'TOTALS',number_format($tb['total_debit']),number_format($tb['total_credit']));
ok('trial balance still balances (744,000 = 744,000)', $tb['balanced'] && $tb['total_debit']===744000);

section('Aged position + P&L unchanged by settlement');
ok('customers still owe (AR) 154,000',    $B('1100') === 154000);
ok('we still owe suppliers (AP) 290,000', -$B('2000') === 290000);
$pl=$report->profitAndLoss('t_ug','e1','UGX');
ok('net profit still 150,000 (cash moves do not change P&L)', $pl['net_profit'] === 150000);

section('Idempotency');
ok('re-post receipt → no-op',             $pay->recordCustomerPayment('t_ug','e1','rcpt-001',200_000) === false);
ok('cash unchanged at -100,000',          $B('1000') === -100000);

echo "\n\033[1m".($fail===0?"\033[32m":"\033[31m")."{$pass} passed, {$fail} failed".($fail===0?" — CASH SETTLEMENT PROVEN":" — FAILURES")."\033[0m\n";
exit($fail===0?0:1);
