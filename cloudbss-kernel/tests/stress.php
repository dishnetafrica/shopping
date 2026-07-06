<?php declare(strict_types=1);
require __DIR__ . '/autoload.php';
// autoload the NEW modules/app (outside the kernel) — proves they are additive, not edits
spl_autoload_register(function (string $c): void {
    foreach ([['CloudBSS\\Modules\\', __DIR__.'/../modules/'], ['CloudBSS\\App\\', __DIR__.'/../app/']] as [$p,$base]) {
        if (str_starts_with($c, $p)) { $f = $base . str_replace('\\','/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; }
    }
});

use CloudBSS\Kernel\Kernel;
use CloudBSS\Kernel\Event\{DomainEvent, EventStore, EventBus};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Ledger\{Posting, LedgerProjection, RegistryAccountingPolicyResolver};
use CloudBSS\Kernel\Ledger\Policies\SouthSudanTradePolicy;
use CloudBSS\Kernel\Decision\Policies\PurchaseApprovalPolicy;
use CloudBSS\Kernel\Support\{FrozenClock, SequenceIdGenerator};
use CloudBSS\Modules\Manufacturing\{ProductionModule, ManufacturingAccountingPolicy};
use CloudBSS\Modules\HR\LeaveModule;
use CloudBSS\App\{CompositeAccountingPolicy, UgandaVatSalesPolicy, StrictPurchaseApprovalPolicy};

$pass=0;$fail=0;
function ok(string $n,bool $c){ global $pass,$fail; if($c){$pass++;echo "  \033[32m✓\033[0m $n\n";} else {$fail++;echo "  \033[31m✗ $n\033[0m\n";} }

$clock=new FrozenClock(); $ids=new SequenceIdGenerator();
$store=new EventStore(); $bus=new EventBus(); $ledger=new LedgerProjection();
$bus->subscribe(Posting::EVENT, fn($rec)=>$ledger->on($rec));

// App composes many posting rules per tenant — kernel unchanged
$resolver=new RegistryAccountingPolicyResolver();
$resolver->register('t_dishnet','e_ss', new CompositeAccountingPolicy([
    new SouthSudanTradePolicy($clock,$ids), new ManufacturingAccountingPolicy($clock,$ids),
]));
$resolver->register('t_win','e_ug', new CompositeAccountingPolicy([
    new UgandaVatSalesPolicy($clock,$ids), new ManufacturingAccountingPolicy($clock,$ids),
]));
$K=new Kernel($store,$bus,$clock,$ids,$resolver);
$biz=fn(string $name,string $agg,array $p,string $t='t_dishnet',string $e='e_ss')=>
    new DomainEvent($ids->next('evt'),$name,1,'Order',$agg,$p,$clock->now(),$t,$e);

echo "\n\033[1mARCHITECTURE STRESS TEST — add new business domains without editing the kernel\033[0m\n";

echo "\n\033[1mTest 1 — add Manufacturing\033[0m\n";
$prod=new ProductionModule($K,$clock,$ids); $prod->subscribe($bus);
$d=$prod->releaseOrder('t_dishnet','e_ss','PO-M1', Money::ofDecimal('500.00','USD'), Money::ofDecimal('1000.00','USD'));
ok('production order auto-released via kernel Decision', $d->outcome==='auto-released');
ok('release raised a shop-floor Task (subscribed on the bus)', count($prod->tasks)===1 && $prod->tasks[0]->status()==='created');
$prod->completeProduction('t_dishnet','e_ss','PO-M1', Money::ofDecimal('500.00','USD'));
ok('manufacturing posting hit the ledger: Finished Goods = 500.00', $ledger->balanceOf('t_dishnet','e_ss','1210','USD')->format()==='USD 500.00');

echo "\n\033[1mTest 2 — add HR (non-financial domain)\033[0m\n";
$hr=new LeaveModule($K,$clock,$ids);
$l1=$hr->requestLeave('t_dishnet','e_ss','emp-1',2);
$l2=$hr->requestLeave('t_dishnet','e_ss','emp-2',10);
ok('short leave auto-approved', !$l1->requiresHuman);
ok('long leave requires human + approval Task opened', $l2->requiresHuman && in_array('TaskOpened', array_map(fn($r)=>$r->name(),$store->all()), true));

echo "\n\033[1mTests 3–6 — Fleet, Asset Mgmt, AI Support, Marketplace (subscribe-only)\033[0m\n";
$fired=[];
foreach (['Fleet'=>'VehicleServiceDue','AssetMgmt'=>'AssetDepreciated','AICustomerSupport'=>'SupportTicketOpened','Marketplace'=>'ListingCreated'] as $mod=>$evt) {
    $bus->subscribe($evt, function($rec) use (&$fired,$mod){ $fired[$mod]=true; });
    $K->recordEvent($biz($evt,$mod.'-1',[]));
}
ok('Fleet plugs in via a subscription', $fired['Fleet'] ?? false);
ok('Asset Management plugs in via a subscription', $fired['AssetMgmt'] ?? false);
ok('AI Customer Support plugs in via a subscription', $fired['AICustomerSupport'] ?? false);
ok('Marketplace plugs in via a subscription', $fired['Marketplace'] ?? false);

echo "\n\033[1mTests 7–9 — another country, VAT, another accounting standard\033[0m\n";
$K->recordEvent($biz('DeliveryCompleted','ORD-UG',['revenue'=>100000,'cost'=>60000,'currency'=>'UGX'],'t_win','e_ug'));
ok('Test 7 — Uganda tenant posts its own way (AR gross = 118,000)', $ledger->balanceOf('t_win','e_ug','1100','UGX')->minor===118000);
ok('Test 8 — 18% output VAT booked (VAT payable = 18,000)', $ledger->balanceOf('t_win','e_ug','2200','UGX')->minor===-18000);
$K->recordEvent($biz('DeliveryCompleted','ORD-SS',['revenue'=>75000,'cost'=>48000,'currency'=>'USD']));
ok('Test 9 — South Sudan standard has NO VAT line for the same event', $ledger->balanceOf('t_dishnet','e_ss','2200','USD')->isZero());
ok('   ...and both books still balance', $ledger->isBalanced());

echo "\n\033[1mTest 10 — another tenant with completely different approval rules\033[0m\n";
$lenient=new PurchaseApprovalPolicy($clock,$ids);
$strict=new StrictPurchaseApprovalPolicy($clock,$ids);
$amt=Money::ofDecimal('300.00','USD'); $thr=Money::ofDecimal('500.00','USD');
$dl=$lenient->evaluate(['tenantId'=>'t_a','entityId'=>'e','amount'=>$amt,'threshold'=>$thr]);
$dsx=$strict->evaluate(['tenantId'=>'t_b','entityId'=>'e','amount'=>$amt]);
ok('lenient tenant auto-approves USD 300', !$dl->requiresHuman);
ok('strict tenant requires human for the SAME 300 (rule differs, kernel does not)', $dsx->requiresHuman);

echo "\n\033[1mKERNEL IMMUTABILITY — did any of this touch the kernel?\033[0m\n";
$before=file('/tmp/kernel-frozen.sha256', FILE_IGNORE_NEW_LINES);
$after=[]; foreach (glob(__DIR__.'/../src/*/*.php') as $f) {} // ensure glob ok
exec('cd '.escapeshellarg(__DIR__.'/..').' && find src -name "*.php" | sort | xargs sha256sum', $after);
ok('every kernel/src file is byte-for-byte unchanged', $before===$after);
$leak=false; foreach (array_merge(glob(__DIR__.'/../modules/*/*.php'), glob(__DIR__.'/../app/*.php')) as $f) { if (str_contains(realpath($f), '/src/')) $leak=true; }
ok('no new module/app file lives inside kernel/src', !$leak);
ok('kernel still has zero framework/tenant imports', (function(){ foreach (glob(__DIR__.'/../src/*/*.php') as $f){ $s=file_get_contents($f); if (preg_match('/Illuminate\\\\|Laravel|PDO|Http\\\\/', $s)) return false; } return true; })());

echo "\n\033[1m".($fail===0?"\033[32m":"\033[31m")."$pass passed, $fail failed — ".($fail===0?"KERNEL SAFE TO FREEZE":"NOT READY")."\033[0m\n";
exit($fail===0?0:1);
