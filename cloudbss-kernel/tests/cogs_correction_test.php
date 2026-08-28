<?php declare(strict_types=1);
/**
 * Phase 3B — CogsCorrectionPolicy over a real (in-memory) kernel + ledger projection.
 *
 * Proves the compensating entry: (1) reverses ONLY the overstatement, (2) is a balanced double-entry,
 * (3) reduces COGS and restores Inventory by the exact amount, (4) leaves the original sale untouched,
 * (5) is idempotent per correction aggregate id, and (6) rejects a non-positive amount.
 *
 * Run:  php tests/cogs_correction_test.php
 */
require __DIR__ . '/autoload.php';
// The shared autoloader maps only CloudBSS\Kernel\ → src/. Map App\ (policies) too.
spl_autoload_register(function (string $class): void {
    $prefix = 'CloudBSS\\App\\';
    if (! str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

use CloudBSS\App\{CompositeAccountingPolicy, UgandaVatSalesPolicy, CogsCorrectionPolicy};
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
    new CogsCorrectionPolicy($clock, $ids),
]));
$K = new Kernel($store, $bus, $clock, $ids, $resolver);

function ev(SequenceIdGenerator $ids, FrozenClock $clock, string $name, string $agg, array $payload): DomainEvent {
    return new DomainEvent($ids->next('evt'), $name, 1, $name === 'CogsCorrected' ? 'CogsCorrection' : 'Sale', $agg, $payload, $clock->now(), 't_ug', 'e1');
}

echo "\n\033[1mCogsCorrectionPolicy — compensating entry over the kernel\033[0m\n";

// The BUG as posted historically: UGX 2,000,000 cost booked as 2,000,000 base minor (USD $20,000.00),
// revenue $825.00. (Kernel amounts are minor units; USD subunit = 100.)
$K->recordEvent(ev($ids, $clock, 'DeliveryCompleted', '151', ['revenue' => 82500, 'cost' => 2000000, 'currency' => 'USD']));
$postedCogs = $ledger->balanceOf('t_ug', 'e1', '5000', 'USD')->minor;
$postedInv  = $ledger->balanceOf('t_ug', 'e1', '1200', 'USD')->minor;
ok('sale posted overstated COGS = 20,000.00', $postedCogs === 2000000);
ok('sale posted Inventory credit = -20,000.00', $postedInv === -2000000);

// Correct COGS should have been ~ $540.54 (UGX 2,000,000 @ 3700/USD). Overstatement = 2000000 - 54054 = 1945946 minor.
$correctCogsMinor = 54054;
$over = $postedCogs - $correctCogsMinor;               // 1,945,946 minor = $19,459.46
$K->recordEvent(ev($ids, $clock, 'CogsCorrected', 'cogsfix_151', [
    'amount' => $over, 'currency' => 'USD', 'sale' => '151',
    'original_posted_cost' => $postedCogs, 'correct_cost' => $correctCogsMinor,
    'reason' => 'multi-currency COGS overstatement (UGX cost booked as base)', 'version' => 'cogs-remediation-v1',
]));

$cogsAfter = $ledger->balanceOf('t_ug', 'e1', '5000', 'USD')->minor;
$invAfter  = $ledger->balanceOf('t_ug', 'e1', '1200', 'USD')->minor;
ok('COGS reduced to the correct 540.54', $cogsAfter === $correctCogsMinor);
ok('Inventory restored to -540.54', $invAfter === -$correctCogsMinor);
ok('correction reversed exactly the overstatement', ($postedCogs - $cogsAfter) === $over);
ok('books still balance after correction', $ledger->isBalanced());

// Revenue / AR / VAT from the original sale are UNTOUCHED by the correction.
ok('Revenue untouched (4000 = -825.00)', $ledger->balanceOf('t_ug', 'e1', '4000', 'USD')->minor === -82500);

// The original sale event is immutable & still present; the correction is a SEPARATE event.
$names = array_map(fn ($r) => $r->name(), $store->all());
ok('original DeliveryCompleted still in the log', in_array('DeliveryCompleted', $names, true));
ok('correction added as a new CogsCorrected event', in_array('CogsCorrected', $names, true));
ok('a JournalPosted exists for the correction', count(array_filter($names, fn ($n) => $n === 'JournalPosted')) >= 2);

// Idempotency is enforced OUTSIDE this policy — by LedgerPoster (Postgres: versionOf(aggregateId) > 0
// → no-op) on the unique correction aggregate id 'cogsfix_<sale>', AND by cogs:remediate's own
// ALREADY_REMEDIATED pre-check (tested in tests/cogs_remediate_harness.php). The in-memory Kernel has
// no aggregate dedup, so we prove here only the consequence that makes that guard necessary: a SECOND,
// DISTINCT correction is additive — hence the per-sale aggregate id must be the idempotency key.
$before = $ledger->balanceOf('t_ug', 'e1', '5000', 'USD')->minor;
$K->recordEvent(ev($ids, $clock, 'CogsCorrected', 'cogsfix_151_dup', ['amount' => $over, 'currency' => 'USD', 'sale' => '151']));
ok('a distinct correction IS additive (so aggregate id is the idempotency key)', $ledger->balanceOf('t_ug', 'e1', '5000', 'USD')->minor === $before - $over);

// A zero / negative "overstatement" is never a correction — the policy refuses it.
threw('zero amount rejected', fn () => (new CogsCorrectionPolicy($clock, $ids))->deriveEntry(ev($ids, $clock, 'CogsCorrected', 'z', ['amount' => 0, 'currency' => 'USD'])), 'positive');
threw('negative amount rejected', fn () => (new CogsCorrectionPolicy($clock, $ids))->deriveEntry(ev($ids, $clock, 'CogsCorrected', 'n', ['amount' => -5, 'currency' => 'USD'])), 'positive');

echo "\n" . ($fail === 0 ? "\033[32mALL PASS ($pass)\033[0m" : "\033[31m$fail FAILURES\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
