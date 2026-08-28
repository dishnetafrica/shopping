<?php declare(strict_types=1);
/**
 * SaleFxCorrectionPolicy over a real (in-memory) kernel + ledger projection.
 * Proves the revenue-leg compensating entry reverses ONLY the overstatement across all three legs
 * (4000 revenue, 2200 VAT, 1100 AR), stays balanced, leaves COGS/Inventory untouched, and rejects
 * a non-positive amount.
 *
 * Run:  php tests/sale_fx_correction_test.php
 */
require __DIR__ . '/autoload.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'CloudBSS\\App\\';
    if (! str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

use CloudBSS\App\{CompositeAccountingPolicy, UgandaVatSalesPolicy, SaleFxCorrectionPolicy};
use CloudBSS\Kernel\Event\{DomainEvent, EventStore, EventBus};
use CloudBSS\Kernel\Ledger\{Posting, LedgerProjection, RegistryAccountingPolicyResolver};
use CloudBSS\Kernel\Support\{FrozenClock, SequenceIdGenerator};
use CloudBSS\Kernel\Kernel;

$pass = 0; $fail = 0;
function ok(string $n, bool $c) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✓\033[0m $n\n"; } else { $fail++; echo "  \033[31m✗ $n\033[0m\n"; } }
function threw(string $n, callable $f, string $needle = '') { global $pass, $fail; try { $f(); $fail++; echo "  \033[31m✗ $n (no exception)\033[0m\n"; } catch (\Throwable $e) { $hit = $needle === '' || str_contains($e->getMessage(), $needle); if ($hit) { $pass++; echo "  \033[32m✓\033[0m $n\n"; } else { $fail++; echo "  \033[31m✗ $n (msg: {$e->getMessage()})\033[0m\n"; } } }

$clock = new FrozenClock(); $ids = new SequenceIdGenerator();
$store = new EventStore(); $bus = new EventBus(); $ledger = new LedgerProjection();
$bus->subscribe(Posting::EVENT, fn ($rec) => $ledger->on($rec));
$resolver = new RegistryAccountingPolicyResolver();
$resolver->register('t_ug', 'e1', new CompositeAccountingPolicy([
    new UgandaVatSalesPolicy($clock, $ids),
    new SaleFxCorrectionPolicy($clock, $ids),
]));
$K = new Kernel($store, $bus, $clock, $ids, $resolver);
$ev = fn (string $name, string $agg, array $p) => new DomainEvent($ids->next('evt'), $name, 1, $name === 'SaleFxCorrected' ? 'SaleFxCorrection' : 'Sale', $agg, $p, $clock->now(), 't_ug', 'e1');

echo "\n\033[1mSaleFxCorrectionPolicy — revenue-leg compensating entry\033[0m\n";

// Sale booked with a UGX net of 2,814,350 as if USD: revenue=281,435,000 minor ($2,814,350.00).
// Policy adds 18% VAT: vat = 50,658,300 ($506,583.00); gross AR = 332,093,300 ($3,320,933.00).
$K->recordEvent($ev('DeliveryCompleted', '42', ['revenue' => 281435000, 'cost' => 0, 'currency' => 'USD']));
$rev0 = $ledger->balanceOf('t_ug', 'e1', '4000', 'USD')->minor;
$vat0 = $ledger->balanceOf('t_ug', 'e1', '2200', 'USD')->minor;
$ar0  = $ledger->balanceOf('t_ug', 'e1', '1100', 'USD')->minor;
ok('sale posted revenue = -281,435,000 (credit)', $rev0 === -281435000);
ok('sale posted VAT = -50,658,300 (18%)', $vat0 === -50658300);
ok('sale posted AR = +332,093,300 (net+vat)', $ar0 === 332093300);

// Correct net = 77,000 minor ($770.00). Net overstatement = 281,435,000 - 77,000 = 281,358,000.
$netOver = 281435000 - 77000;
$K->recordEvent($ev('SaleFxCorrected', 'salefx_42', [
    'net_amount' => $netOver, 'currency' => 'USD', 'vat_bp' => 1800, 'sale' => '42',
    'reason' => 'UGX net booked as base without conversion', 'version' => 'sale-fx-remediation-v1',
]));

$rev1 = $ledger->balanceOf('t_ug', 'e1', '4000', 'USD')->minor;
$vat1 = $ledger->balanceOf('t_ug', 'e1', '2200', 'USD')->minor;
$ar1  = $ledger->balanceOf('t_ug', 'e1', '1100', 'USD')->minor;
ok('revenue reduced to the correct -77,000', $rev1 === -77000);
ok('VAT reduced to correct -13,860 (18% of 77,000)', $vat1 === -13860);
ok('AR reduced to correct +90,860 (net+vat)', $ar1 === 90860);
ok('AR == revenue + VAT after correction', -$ar1 === $rev1 + $vat1);
ok('books still balance after correction', $ledger->isBalanced());

// COGS/Inventory are not touched by a revenue correction (this sale had cost 0 anyway).
ok('COGS untouched (5000 zero)', $ledger->balanceOf('t_ug', 'e1', '5000', 'USD')->isZero());

$names = array_map(fn ($r) => $r->name(), $store->all());
ok('original DeliveryCompleted still present', in_array('DeliveryCompleted', $names, true));
ok('correction added as new SaleFxCorrected event', in_array('SaleFxCorrected', $names, true));

// A distinct correction is additive → the per-sale aggregate id is the idempotency key (enforced by
// LedgerPoster/versionOf in Postgres + the command's ALREADY check).
$before = $ledger->balanceOf('t_ug', 'e1', '4000', 'USD')->minor;
$K->recordEvent($ev('SaleFxCorrected', 'salefx_42_dup', ['net_amount' => 100, 'currency' => 'USD', 'vat_bp' => 1800, 'sale' => '42']));
// 4000 is a credit-balance (income) account; a debit moves it toward zero, so -77000 + 100.
ok('distinct correction is additive', $ledger->balanceOf('t_ug', 'e1', '4000', 'USD')->minor === $before + 100);

// Non-positive amount rejected.
threw('zero net rejected', fn () => (new SaleFxCorrectionPolicy($clock, $ids))->deriveEntry($ev('SaleFxCorrected', 'z', ['net_amount' => 0, 'currency' => 'USD'])), 'positive');
threw('negative net rejected', fn () => (new SaleFxCorrectionPolicy($clock, $ids))->deriveEntry($ev('SaleFxCorrected', 'n', ['net_amount' => -5, 'currency' => 'USD'])), 'positive');

echo "\n" . ($fail === 0 ? "\033[32mALL PASS ($pass)\033[0m" : "\033[31m$fail FAILURES\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
