<?php declare(strict_types=1);

/**
 * End-to-end durability proof for the Postgres adapter — against a REAL Postgres.
 *
 * Scenario (reusing the kernel's own UgandaVatSalesPolicy, kernel untouched):
 *   BOOT 1  record a DeliveryCompleted sale -> kernel auto-posts JournalPosted -> ledger balances,
 *           and every event is persisted to Postgres.
 *   BOOT 2  a FRESH process (new in-memory store/bus/kernel/ledger) rehydrates from Postgres and
 *           must reproduce the EXACT balances — with NO double-posting — then continue the log
 *           correctly for a new sale.
 *
 * Run:  CLOUDBSS_PG_DSN="pgsql:host=/tmp;port=5433;dbname=cloudbss" php infra/tests/persistence_test.php
 */

// ---- autoload kernel (src/), app policies (app/), infra adapter (infra/) ----
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
use CloudBSS\Infra\Persistence;
use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Kernel\Event\{DomainEvent, EventBus, EventStore};
use CloudBSS\Kernel\Kernel;
use CloudBSS\Kernel\Ledger\{LedgerProjection, Posting, RegistryAccountingPolicyResolver};
use CloudBSS\Kernel\Support\{FrozenClock, RandomIdGenerator};

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✓\033[0m {$label}\n"; }
    else       { $fail++; echo "  \033[31m✗ {$label}\033[0m\n"; }
}
function section(string $s): void { echo "\n\033[1m{$s}\033[0m\n"; }

$dsn = getenv('CLOUDBSS_PG_DSN') ?: 'pgsql:host=/tmp;port=5433;dbname=cloudbss';
$pdo = new \PDO($dsn, getenv('CLOUDBSS_PG_USER') ?: 'postgres', getenv('CLOUDBSS_PG_PASS') ?: '');

$log = new PostgresEventLog($pdo);
$log->migrate();
$pdo->exec('TRUNCATE cloudbss_events RESTART IDENTITY');   // clean slate for the test

// A helper that builds a fresh, empty in-memory kernel wired to the ledger + Uganda policy.
$build = function () {
    $store  = new EventStore();
    $bus    = new EventBus();
    $clock  = new FrozenClock('2026-05-20T09:00:00+00:00');
    $ids    = new RandomIdGenerator();   // unique ids across boots (production generator)
    $ledger = new LedgerProjection();
    $bus->subscribe(Posting::EVENT, [$ledger, 'on']);           // ledger folds postings (live)

    $resolver = new RegistryAccountingPolicyResolver();
    $resolver->register('t_ug', 'e1', new UgandaVatSalesPolicy($clock, $ids));

    $kernel = new Kernel($store, $bus, $clock, $ids, $resolver); // wires the auto-posting reactor
    return compact('store', 'bus', 'clock', 'ids', 'ledger', 'kernel');
};

$sale = function (Kernel $kernel, string $saleId, int $revenue, int $cost, \DateTimeImmutable $at): void {
    $kernel->recordEvent(new DomainEvent(
        eventId: 'evt_' . $saleId, name: 'DeliveryCompleted', schemaVersion: 1,
        aggregateType: 'Sale', aggregateId: $saleId,
        payload: ['revenue' => $revenue, 'cost' => $cost, 'currency' => 'UGX'],
        occurredAt: $at, tenantId: 't_ug', entityId: 'e1', correlationId: 'ord_' . $saleId
    ));
};

// =====================================================================================
section('BOOT 1 — record a sale; kernel posts VAT journal; everything persists to Postgres');
$b1 = $build();
Persistence::persistOnPublish($b1['bus'], $log);                 // durable-write for NEW events

$pdo->beginTransaction();
$sale($b1['kernel'], 'sale-001', 100_000, 60_000, new \DateTimeImmutable('2026-05-20T09:00:00+00:00'));
$pdo->commit();

$L1 = $b1['ledger'];
ok('AR (1100) debit = gross 118,000',        $L1->balanceOf('t_ug','e1','1100','UGX')->minor === 118000);
ok('Sales (4000) credit = net 100,000',      $L1->balanceOf('t_ug','e1','4000','UGX')->minor === -100000);
ok('VAT output (2200) credit = 18,000',      $L1->balanceOf('t_ug','e1','2200','UGX')->minor === -18000);
ok('COGS (5000) debit = 60,000',             $L1->balanceOf('t_ug','e1','5000','UGX')->minor === 60000);
ok('Inventory (1200) credit = 60,000',       $L1->balanceOf('t_ug','e1','1200','UGX')->minor === -60000);
ok('book balances to zero',                   $L1->isBalanced());
ok('2 events persisted (DeliveryCompleted + JournalPosted)', $log->count() === 2);
ok('in-memory store also has 2',              $b1['store']->count() === 2);

// =====================================================================================
section('BOOT 2 — a FRESH process rehydrates from Postgres (no double-posting)');
$b2 = $build();                                                  // brand-new empty in-memory kernel
$replayed = Persistence::rehydrate($b2['store'], $log);          // replay history into the store (no publish)
$b2['ledger']->rebuildFrom($b2['store']->all());                // rebuild balances from full history

$L2 = $b2['ledger'];
ok('replayed 2 events from Postgres',         $replayed === 2);
ok('rehydrated store has 2 events',           $b2['store']->count() === 2);
$jp = count(array_filter($b2['store']->all(), fn($r) => $r->name() === Posting::EVENT));
ok('exactly ONE JournalPosted (no double-post on replay)', $jp === 1);
ok('AR rebuilt = 118,000 (identical)',        $L2->balanceOf('t_ug','e1','1100','UGX')->minor === 118000);
ok('VAT rebuilt = 18,000 (identical)',        $L2->balanceOf('t_ug','e1','2200','UGX')->minor === -18000);
ok('Sales rebuilt = 100,000 (identical)',     $L2->balanceOf('t_ug','e1','4000','UGX')->minor === -100000);
ok('rebuilt book still balances',             $L2->isBalanced());

// =====================================================================================
section('BOOT 2 continues the log — a new sale appends after history, persists, projects');
Persistence::persistOnPublish($b2['bus'], $log);                 // attach durable-write AFTER rehydrate
$pdo->beginTransaction();
$sale($b2['kernel'], 'sale-002', 200_000, 90_000, new \DateTimeImmutable('2026-05-21T10:00:00+00:00'));
$pdo->commit();

ok('log grew to 4 events',                    $log->count() === 4);
ok('new events continued global_position 3,4',$log->lastPosition() === 4);
ok('AR now 118,000 + 236,000 = 354,000',      $L2->balanceOf('t_ug','e1','1100','UGX')->minor === 354000);
ok('VAT now 18,000 + 36,000 = 54,000',        $L2->balanceOf('t_ug','e1','2200','UGX')->minor === -54000);
ok('book still balances after 2nd sale',      $L2->isBalanced());
ok('versionOf(sale-002) = 1 in Postgres',     $log->versionOf('sale-002') === 1);

// =====================================================================================
echo "\n\033[1m" . ($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} passed, {$fail} failed"
   . ($fail === 0 ? " — DURABLE PERSISTENCE PROVEN" : " — FAILURES") . "\033[0m\n";
exit($fail === 0 ? 0 : 1);
