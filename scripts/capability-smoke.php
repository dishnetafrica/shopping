<?php
/**
 * Proves the assistant brief kernel + DTO envelope work in isolation (no Laravel, no DB).
 * Run: php scripts/capability-smoke.php
 */
$root = dirname(__DIR__);
require $root . '/app/Capability/Dto/ActionSpec.php';
require $root . '/app/Capability/Dto/Brief.php';
require $root . '/app/Capability/Dto/Financial.php';
require $root . '/app/Capability/Dto/TimelineEvent.php';
require $root . '/app/Capability/Dto/Related.php';
require $root . '/app/Capability/Dto/WorkspacePayload.php';
require $root . '/app/Capability/Shared/AssistantCapability.php';

use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Shared\AssistantCapability;

$a = new AssistantCapability();
$fail = 0;
$assert = function (bool $c, string $m) use (&$fail) { echo ($c ? '  ok  ' : ' FAIL ') . $m . "\n"; if (! $c) $fail++; };

// 1) portfolio brief (>=2 overdue)
$b = $a->customerBrief(['name' => 'Blue Logistics', 'overdue' => 18420, 'overdueCount' => 4, 'maxDaysLate' => 38, 'exposure' => 18420, 'balance' => 18420, 'endpoint' => '/api/v1/workspace/customer/7'])->toArray();
$assert($b['level'] === 'high', 'portfolio brief high risk');
$assert(str_contains($b['situation'], '4 invoices overdue'), 'portfolio situation names 4 invoices');
$assert(count($b['actions']) === 2 && $b['actions'][0]['endpoint'] === '/api/v1/workspace/customer/7/record_payment', 'actions point at command endpoints');

// 2) healthy
$h = $a->customerBrief(['name' => 'Nile', 'overdue' => 0, 'overdueCount' => 0, 'exposure' => 0, 'balance' => 0, 'endpoint' => '/x'])->toArray();
$assert($h['level'] === 'low' && $h['risk']['tone'] === 'ok', 'healthy brief is low/ok');

// 3) envelope assembles + is pure array
$p = (new WorkspacePayload)
    ->object(['type' => 'customer', 'id' => '7'])
    ->headline('$18,420', 'outstanding balance', 'warn')
    ->financials([new Financial('balance', 'Balance', 18420, 'USD', 'warn')])
    ->related([new Related('invoices', 'invoice', null, null, null, 4)])
    ->meta(['capability' => 'customer', 'ttl' => 60])
    ->toArray();
$assert($p['object']['type'] === 'customer', 'envelope object set');
$assert($p['financials'][0]['value'] === 18420, 'envelope financials serialised');
$assert($p['meta']['version'] === '1.0', 'envelope carries contract version');
$assert(json_encode($p) !== false, 'envelope is JSON-serialisable');

echo $fail === 0 ? "\nSMOKE PASS\n" : "\nSMOKE FAILED ($fail)\n";
exit($fail === 0 ? 0 : 1);
