<?php declare(strict_types=1);

/**
 * VERTICAL SLICE — a confirmed ShopBot/Patronics sale becomes a balanced, durable, queryable
 * ledger entry, via the kernel's UgandaVatSalesPolicy. Simulates SEPARATE web requests: each
 * recordSale() builds its own fresh kernel (no shared in-memory state, no full-log replay) and
 * reads balances straight from Postgres.
 *
 * Run: CLOUDBSS_PG_DSN="pgsql:host=/tmp;port=5433;dbname=cloudbss" php infra/tests/sales_slice_test.php
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

use CloudBSS\App\UgandaVatSalesPolicy;
use CloudBSS\Infra\SalesLedger;
use CloudBSS\Infra\Postgres\{PostgresEventLog, PostgresLedgerProjection};
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

// Each "web request" wires its own SalesLedger (fresh kernel collaborators, shared DB).
$request = function () use ($pdo): SalesLedger {
    $log    = new PostgresEventLog($pdo);
    $ledger = new PostgresLedgerProjection($pdo);
    $clock  = new SystemClock();
    $ids    = new RandomIdGenerator();
    $resolver = new RegistryAccountingPolicyResolver();
    $resolver->register('t_ug', 'e1', new UgandaVatSalesPolicy($clock, $ids));  // Patronics policy
    return new SalesLedger($pdo, $log, $ledger, $clock, $ids, $resolver);
};

$B = fn(string $acc, string $cur = 'UGX') => (new PostgresLedgerProjection($pdo))
        ->balanceOf('t_ug', 'e1', $acc, $cur)->minor;

// =====================================================================================
section('REQUEST 1 — Patronics order sale-001 confirmed (net 100,000 UGX, cost 60,000)');
$r1 = ($request())->recordSale('t_ug', 'e1', 'sale-001', 100_000, 60_000, 'UGX');
ok('recorded (new posting)',               $r1 === true);
ok('AR (1100) = gross 118,000',            $B('1100') === 118000);
ok('Sales (4000) = -100,000',              $B('4000') === -100000);
ok('VAT output (2200) = -18,000',          $B('2200') === -18000);
ok('COGS (5000) = 60,000',                 $B('5000') === 60000);
ok('Inventory (1200) = -60,000',           $B('1200') === -60000);
ok('book balances',                        (new PostgresLedgerProjection($pdo))->isBalanced('t_ug','e1'));
ok('2 events in the durable log',          $log->count() === 2);

// =====================================================================================
section('REQUEST 2 — a SEPARATE request records sale-002 (net 200,000, cost 90,000)');
$r2 = ($request())->recordSale('t_ug', 'e1', 'sale-002', 200_000, 90_000, 'UGX');
ok('recorded (new posting)',               $r2 === true);
ok('AR accumulated = 354,000',             $B('1100') === 354000);
ok('VAT accumulated = -54,000',            $B('2200') === -54000);
ok('Sales accumulated = -300,000',         $B('4000') === -300000);
ok('log now has 4 events',                 $log->count() === 4);
ok('book still balances',                  (new PostgresLedgerProjection($pdo))->isBalanced('t_ug','e1'));

// =====================================================================================
section('REQUEST 3 — sale-001 confirmed AGAIN (double-tap / webhook retry) is idempotent');
$r3 = ($request())->recordSale('t_ug', 'e1', 'sale-001', 100_000, 60_000, 'UGX');
ok('no new posting (idempotent)',          $r3 === false);
ok('AR unchanged at 354,000',              $B('1100') === 354000);
ok('log still 4 events (no double-post)',  $log->count() === 4);

// =====================================================================================
section('RECOVERY — rebuild the balances table from the events log alone');
$fresh = new PostgresLedgerProjection($pdo);
$fresh->rebuildFrom($log->load());
ok('AR rebuilt = 354,000 (pure projection)', $fresh->balanceOf('t_ug','e1','1100','UGX')->minor === 354000);
ok('VAT rebuilt = -54,000',                  $fresh->balanceOf('t_ug','e1','2200','UGX')->minor === -54000);
ok('rebuilt book balances',                  $fresh->isBalanced('t_ug','e1'));

echo "\n\033[1m" . ($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} passed, {$fail} failed"
   . ($fail === 0 ? " — SALES→LEDGER SLICE PROVEN" : " — FAILURES") . "\033[0m\n";
exit($fail === 0 ? 0 : 1);
