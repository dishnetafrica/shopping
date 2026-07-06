<?php declare(strict_types=1);

/**
 * SUBSIDIARY LEDGER + AGING — per-customer receivables with due dates. Proves: sales to different
 * customers create per-party open items, a payment settles oldest-first, aging buckets are right,
 * and the subledger total RECONCILES to the GL AR control account (1100).
 *
 * Run: CLOUDBSS_PG_DSN="pgsql:host=/tmp;port=5433;dbname=cloudbss" php infra/tests/aging_test.php
 */

spl_autoload_register(function (string $class): void {
    $roots = ['CloudBSS\\Kernel\\'=>__DIR__.'/../../src/','CloudBSS\\App\\'=>__DIR__.'/../../app/','CloudBSS\\Infra\\'=>__DIR__.'/../'];
    foreach ($roots as $p=>$d) { if (str_starts_with($class,$p)) { $f=$d.str_replace('\\','/',substr($class,strlen($p))).'.php'; if(is_file($f)) require $f; return; } }
});

use CloudBSS\App\{UgandaVatSalesPolicy, CashSettlementPolicy, CompositeAccountingPolicy};
use CloudBSS\Infra\{LedgerPoster, SalesLedger, PaymentLedger};
use CloudBSS\Infra\Postgres\{PostgresEventLog, PostgresLedgerProjection, PostgresSubledgerProjection};
use CloudBSS\Infra\Reporting\Aging;
use CloudBSS\Kernel\Ledger\RegistryAccountingPolicyResolver;
use CloudBSS\Kernel\Support\{SystemClock, RandomIdGenerator};

$pass=0;$fail=0;
function ok(string $l,bool $c):void{global $pass,$fail; if($c){$pass++;echo "  \033[32m✓\033[0m {$l}\n";}else{$fail++;echo "  \033[31m✗ {$l}\033[0m\n";}}
function section(string $s):void{echo "\n\033[1m{$s}\033[0m\n";}

$dsn=getenv('CLOUDBSS_PG_DSN')?:'pgsql:host=/tmp;port=5433;dbname=cloudbss';
$pdo=new \PDO($dsn, getenv('CLOUDBSS_PG_USER')?:'postgres', getenv('CLOUDBSS_PG_PASS')?:'');
$log=new PostgresEventLog($pdo); $log->migrate();
$pdo->exec('TRUNCATE cloudbss_events RESTART IDENTITY; TRUNCATE cloudbss_ledger_balances; TRUNCATE cloudbss_subledger;');

$clock=new SystemClock(); $ids=new RandomIdGenerator();
$resolver=new RegistryAccountingPolicyResolver();
$resolver->register('t_ug','e1', new CompositeAccountingPolicy([
    new UgandaVatSalesPolicy($clock,$ids), new CashSettlementPolicy($clock,$ids),
]));
$subledger=new PostgresSubledgerProjection($pdo);
$poster=new LedgerPoster($pdo,new PostgresEventLog($pdo),new PostgresLedgerProjection($pdo),$clock,$ids,$resolver,$subledger);
$sales=new SalesLedger($poster); $pay=new PaymentLedger($poster);
$aging=new Aging($pdo);
$today=new \DateTimeImmutable('2026-07-06');

section('Sales on credit to two customers, different due dates');
// Speke: net 100,000 -> AR gross 118,000, due 45 days ago (overdue)
$sales->recordSale('t_ug','e1','INV-001',100_000,60_000,'UGX',null,'speke','Speke Hotel','2026-05-22');
// Speke: another, net 50,000 -> AR 59,000, due in future (current)
$sales->recordSale('t_ug','e1','INV-002', 50_000,30_000,'UGX',null,'speke','Speke Hotel','2026-08-01');
// Nile: net 200,000 -> AR 236,000, due 20 days ago (1-30 bucket)
$sales->recordSale('t_ug','e1','INV-003',200_000,120_000,'UGX',null,'nile','Nile Breweries','2026-06-16');
$arControl = $poster->balance('t_ug','e1','1100','UGX')->minor;
ok('GL AR control = 118k+59k+236k = 413,000', $arControl === 413000);
ok('subledger total reconciles to GL AR',     $aging->totalOutstanding('t_ug','e1','AR') === 413000);

section('Speke pays 118,000 — settles oldest (INV-001) first');
$pay->recordCustomerPayment('t_ug','e1','RCPT-01',118_000,'UGX',null,'speke');
ok('GL AR control now 295,000',               $poster->balance('t_ug','e1','1100','UGX')->minor === 295000);
ok('subledger total now 295,000 (reconciles)',$aging->totalOutstanding('t_ug','e1','AR') === 295000);

section('Debtors aging (as of 2026-07-06)');
$rows=$aging->byParty('t_ug','e1','AR','UGX',$today);
foreach ($rows as $r) printf("    %-14s cur %9s  1-30 %9s  31-60 %9s  61-90 %9s  90+ %9s  = %10s\n",
    substr($r['name'],0,14),number_format($r['current']),number_format($r['d1_30']),number_format($r['d31_60']),
    number_format($r['d61_90']),number_format($r['d90_plus']),number_format($r['total']));
$byName=[]; foreach($rows as $r) $byName[$r['party']]=$r;
ok('Nile is top debtor (236,000)',            ($rows[0]['party'] ?? '') === 'nile' && $rows[0]['total'] === 236000);
ok('Nile 236,000 in 1-30 bucket (20 days overdue)', ($byName['nile']['d1_30'] ?? 0) === 236000);
ok('Speke remaining = 59,000 (INV-001 cleared, INV-002 open)', ($byName['speke']['total'] ?? 0) === 59000);
ok('Speke 59,000 is current (due in future)', ($byName['speke']['current'] ?? 0) === 59000);

section('Recovery — subledger rebuilds from the events log');
$fresh=new PostgresSubledgerProjection($pdo);
$fresh->rebuildFrom($log->load());
ok('rebuilt AR total still 295,000',          $aging->totalOutstanding('t_ug','e1','AR') === 295000);

echo "\n\033[1m".($fail===0?"\033[32m":"\033[31m")."{$pass} passed, {$fail} failed".($fail===0?" — SUBLEDGER + AGING PROVEN":" — FAILURES")."\033[0m\n";
exit($fail===0?0:1);
