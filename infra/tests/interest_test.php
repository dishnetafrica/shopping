<?php declare(strict_types=1);

/**
 * OVERDUE INTEREST + STATEMENT. An overdue invoice accrues 3%/month interest (Dr AR, Cr Interest
 * Income), which raises the customer's balance, books income in the P&L, appears on their statement,
 * and reconciles to the GL — and re-running the same month is idempotent (no double interest).
 *
 * Run: CLOUDBSS_PG_DSN="pgsql:host=/tmp;port=5433;dbname=cloudbss" php infra/tests/interest_test.php
 */

spl_autoload_register(function (string $class): void {
    $roots = ['CloudBSS\\Kernel\\'=>__DIR__.'/../../src/','CloudBSS\\App\\'=>__DIR__.'/../../app/','CloudBSS\\Infra\\'=>__DIR__.'/../'];
    foreach ($roots as $p=>$d) { if (str_starts_with($class,$p)) { $f=$d.str_replace('\\','/',substr($class,strlen($p))).'.php'; if(is_file($f)) require $f; return; } }
});

use CloudBSS\App\{UgandaVatSalesPolicy, CashSettlementPolicy, InterestPolicy, CompositeAccountingPolicy};
use CloudBSS\Infra\{LedgerPoster, SalesLedger, InterestRun};
use CloudBSS\Infra\Postgres\{PostgresEventLog, PostgresLedgerProjection, PostgresSubledgerProjection};
use CloudBSS\Infra\Reporting\{TrialBalance, Statement, Aging};
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
    new UgandaVatSalesPolicy($clock,$ids), new CashSettlementPolicy($clock,$ids), new InterestPolicy($clock,$ids),
]));
$poster=new LedgerPoster($pdo,new PostgresEventLog($pdo),new PostgresLedgerProjection($pdo),$clock,$ids,$resolver,new PostgresSubledgerProjection($pdo));
$sales=new SalesLedger($poster);
$interest=new InterestRun($pdo,$poster);
$stmt=new Statement($pdo); $tb=new TrialBalance($pdo); $aging=new Aging($pdo);
$asOf=new \DateTimeImmutable('2026-07-06');
$B=fn(string $a)=>$poster->balance('t_ug','e1',$a,'UGX')->minor;

section('An overdue invoice to Speke (net 100,000 → AR 118,000, due 2026-05-22)');
$sales->recordSale('t_ug','e1','INV-001',100_000,60_000,'UGX',null,'speke','Speke Hotel','2026-05-22');
ok('AR = 118,000',                        $B('1100') === 118000);

section('Run 3%/month interest as of 2026-07-06');
$charges=$interest->run('t_ug','e1',$asOf,300);        // 300 bp = 3%
ok('one interest charge posted',          count($charges) === 1);
ok('interest = 3% of 118,000 = 3,540',    ($charges[0]['interest'] ?? 0) === 3540);
ok('AR now 121,540',                      $B('1100') === 121540);
ok('Interest Income (4100) = 3,540 Cr',   $B('4100') === -3540);
ok('trial balance still balances',        $tb->trialBalance('t_ug','e1','UGX')['balanced']);

section('Idempotent: re-run same month charges nothing more');
$again=$interest->run('t_ug','e1',$asOf,300);
ok('no new charge on re-run',             count($again) === 0);
ok('AR unchanged at 121,540',             $B('1100') === 121540);

section('Speke statement');
$s=$stmt->forParty('t_ug','e1','speke','AR','UGX',$asOf);
foreach ($s['items'] as $it) printf("    %-22s out %10s  due %-12s  %3d days overdue\n",
    $it['ref'], number_format($it['outstanding']), $it['due_date'] ?? '-', $it['days_overdue']);
printf("    %-22s TOTAL OWED %10s\n", $s['name'], number_format($s['total_outstanding']));
ok('statement has 2 lines (invoice + interest)', count($s['items']) === 2);
ok('total owed = 121,540',                $s['total_outstanding'] === 121540);
ok('statement reconciles to GL AR',       $s['total_outstanding'] === $B('1100'));
ok('P&L income now includes interest (Sales 100k + Int 3,540)',
    $tb->profitAndLoss('t_ug','e1','UGX')['income'] === 103540);

echo "\n\033[1m".($fail===0?"\033[32m":"\033[31m")."{$pass} passed, {$fail} failed".($fail===0?" — INTEREST + STATEMENT PROVEN":" — FAILURES")."\033[0m\n";
exit($fail===0?0:1);
