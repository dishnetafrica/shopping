<?php declare(strict_types=1);
require __DIR__ . '/autoload.php';

use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Participant\{Party, DigitalParticipant};
use CloudBSS\Kernel\Event\{DomainEvent, EventStore, EventBus, ConcurrencyException};
use CloudBSS\Kernel\Work\Task;
use CloudBSS\Kernel\Decision\Policies\{SupplierSelectionPolicy, PurchaseApprovalPolicy};
use CloudBSS\Kernel\Ledger\{JournalEntry, JournalLine, Account, Posting, LedgerProjection, RegistryAccountingPolicyResolver};
use CloudBSS\Kernel\Ledger\Policies\SouthSudanTradePolicy;
use CloudBSS\Kernel\Support\{FrozenClock, SequenceIdGenerator};
use CloudBSS\Kernel\Kernel;

$pass=0;$fail=0;
function G(string $g){ echo "\n\033[1m$g\033[0m\n"; }
function ok(string $n,bool $c){ global $pass,$fail; if($c){$pass++;echo "  \033[32m✓\033[0m $n\n";} else {$fail++;echo "  \033[31m✗ $n\033[0m\n";} }
function threw(string $n,callable $f,string $needle=''){ global $pass,$fail; try{$f();$fail++;echo "  \033[31m✗ $n (no exception)\033[0m\n";}catch(\Throwable $e){$hit=$needle===''||str_contains($e->getMessage(),$needle);if($hit){$pass++;echo "  \033[32m✓\033[0m $n\n";}else{$fail++;echo "  \033[31m✗ $n (msg: {$e->getMessage()})\033[0m\n";}} }

/** Build a fully wired, deterministic kernel + ledger projection. */
function wire(): array {
    $clock=new FrozenClock(); $ids=new SequenceIdGenerator();
    $store=new EventStore(); $bus=new EventBus(); $ledger=new LedgerProjection();
    $bus->subscribe(Posting::EVENT, fn($rec)=>$ledger->on($rec));
    $resolver=new RegistryAccountingPolicyResolver();
    $resolver->register('t_dishnet','e_ss', new SouthSudanTradePolicy($clock,$ids));
    $K=new Kernel($store,$bus,$clock,$ids,$resolver);
    return [$K,$store,$ledger,$clock,$ids,$resolver];
}
function biz(SequenceIdGenerator $ids, FrozenClock $clock, string $name, string $agg, array $payload): DomainEvent {
    return new DomainEvent($ids->next('evt'),$name,1,'Order',$agg,$payload,$clock->now(),'t_dishnet','e_ss');
}

/* ---------- MONEY (preserved) + overflow guard (C3) ---------- */
G('Money — integer minor units, and overflow now THROWS (no silent float)');
ok('1930.00 USD -> 193000 minor', Money::ofDecimal('1930.00','USD')->minor===193000);
ok('6.20 * 1.45 = 8.99, never 0', Money::ofDecimal('6.20','USD')->scaledBy(145,100)->minor===899);
ok('allocate 100 by 1:1:1 loses no cent', array_sum(array_map(fn($m)=>$m->minor, Money::ofMinor(100,'USD')->allocate(1,1,1)))===100);
threw('multiply overflow throws (not float)', fn()=> Money::ofMinor(PHP_INT_MAX,'USD')->timesQty(2), 'overflow');
threw('add overflow throws (not float)', fn()=> Money::ofMinor(PHP_INT_MAX,'USD')->plus(Money::ofMinor(1,'USD')), 'overflow');
threw('decimal too large throws', fn()=> Money::ofDecimal('99999999999999999999','USD'), 'too large');

G('Regression — native USD quote must NOT round to 0.00');
$tot=Money::ofDecimal('33.00','USD')->timesQty(8)->plus(Money::ofDecimal('81.00','USD')->timesQty(6));
ok('8×33 + 6×81 = USD 750.00, not 0', $tot->format()==='USD 750.00' && !$tot->isZero());

/* ---------- ONE WRITE MODEL: business event -> JournalPosted -> ledger projection ---------- */
G('One write model — ledger is a PROJECTION of JournalPosted events (C1)');
[$K,$store,$ledger,$clock,$ids]=wire();
$K->recordEvent(biz($ids,$clock,'DeliveryCompleted','ORD-77',['revenue'=>75000,'cost'=>48000,'currency'=>'USD']));
ok('projection AR = 750.00', $ledger->balanceOf('t_dishnet','e_ss','1100','USD')->format()==='USD 750.00');
ok('projection COGS = 480.00', $ledger->balanceOf('t_dishnet','e_ss','5000','USD')->minor===48000);
$K->recordEvent(biz($ids,$clock,'CODCollected','ORD-77',['collected'=>63000,'short'=>12000,'currency'=>'USD']));
ok('projection cash = 630.00 on partial COD', $ledger->balanceOf('t_dishnet','e_ss','1000','USD')->minor===63000);
ok('projection shortfall = 120.00', $ledger->balanceOf('t_dishnet','e_ss','1150','USD')->minor===12000);
ok('projection AR cleared to zero', $ledger->balanceOf('t_dishnet','e_ss','1100','USD')->isZero());
ok('book balances', $ledger->isBalanced());
$names=array_map(fn($r)=>$r->name(), $store->all());
ok('store holds business events AND JournalPosted', in_array('DeliveryCompleted',$names,true) && in_array('JournalPosted',$names,true));
ok('every posting is causally linked to its business event', (function() use($store){ foreach($store->all() as $r){ if($r->name()==='JournalPosted' && $r->event->causationId===null) return false;} return true; })());

/* ---------- RECONSTRUCTABILITY: rebuild ledger from the log alone ---------- */
G('Replay — rebuild every balance from events alone (your test)');
$fresh=new LedgerProjection();
$fresh->rebuildFrom($store->all());
ok('rebuilt cash = 630.00', $fresh->balanceOf('t_dishnet','e_ss','1000','USD')->minor===63000);
ok('rebuilt AR = 0 (identical to live projection)', $fresh->balanceOf('t_dishnet','e_ss','1100','USD')->isZero());
ok('rebuilt book balances', $fresh->isBalanced());

/* ---------- TENANT ISOLATION (M3) — kernel stays tenant-agnostic ---------- */
G('Tenant isolation — balances scoped per tenant/entity, resolver picks policy');
[$K2,$store2,$led2,$clk2,$ids2,$resolver2]=wire();
$resolver2->register('t_win','e_ug', new SouthSudanTradePolicy($clk2,$ids2));
$K2->recordEvent(biz($ids2,$clk2,'DeliveryCompleted','ORD-A',['revenue'=>75000,'cost'=>48000,'currency'=>'USD']));
$K2->recordEvent(new DomainEvent($ids2->next('evt'),'DeliveryCompleted',1,'Order','ORD-B',['revenue'=>20000,'cost'=>12000,'currency'=>'USD'],$clk2->now(),'t_win','e_ug'));
ok('tenant DishNet AR = 750.00', $led2->balanceOf('t_dishnet','e_ss','1100','USD')->minor===75000);
ok('tenant WinWorld AR = 200.00 (independent)', $led2->balanceOf('t_win','e_ug','1100','USD')->minor===20000);
ok('no cross-tenant leakage', $led2->balanceOf('t_win','e_ug','1100','USD')->minor !== $led2->balanceOf('t_dishnet','e_ss','1100','USD')->minor);

/* ---------- BALANCED-OR-REJECT still enforced at posting ---------- */
G('Ledger — unbalanced posting rejected before it can become an event');
$cash=new Account('1000','Cash','asset'); $rev=new Account('4000','Revenue','income');
$bad=new JournalEntry('t','e','oops',[JournalLine::debit($cash,Money::ofDecimal('750.00','USD')),JournalLine::credit($rev,Money::ofDecimal('700.00','USD'))]);
threw('unbalanced entry cannot be posted', fn()=> Posting::toEvent($bad,'evt',(new FrozenClock())->now()), 'unbalanced');

/* ---------- EVENT METADATA + CONCURRENCY (C4) ---------- */
G('Events — sequence, global position, optimistic concurrency (C4)');
$es=new EventStore();
$r1=$es->append(new DomainEvent('e1','X',1,'Agg','AGG-1',[], (new FrozenClock())->now(),'t','e'), 0);
$r2=$es->append(new DomainEvent('e2','Y',1,'Agg','AGG-1',[], (new FrozenClock())->now(),'t','e'), 1);
ok('per-aggregate sequence increments 1,2', $r1->aggregateSequence===1 && $r2->aggregateSequence===2);
ok('global position increments', $r2->globalPosition===2);
threw('stale expectedVersion -> ConcurrencyException', fn()=> $es->append(new DomainEvent('e3','Z',1,'Agg','AGG-1',[], (new FrozenClock())->now(),'t','e'), 1), 'Concurrency');

/* ---------- DETERMINISTIC REPLAY (M5) ---------- */
G('Determinism — same script + FrozenClock + SequenceIds => identical event stream');
$sig=function(){ [$K,$store,$led,$clock,$ids,$res]=wire(); $K->recordEvent(biz($ids,$clock,'DeliveryCompleted','ORD-77',['revenue'=>75000,'cost'=>48000,'currency'=>'USD'])); return array_map(fn($r)=>[$r->event->eventId,$r->name(),$r->globalPosition], $store->all()); };
ok('two independent runs produce identical (id,name,position) streams', $sig()===$sig());

/* ---------- DECISION — versioned, structured, explainable (M4) ---------- */
G('Decision — records policy VERSION + structured inputs (M4)');
[$K3,$store3,$led3,$clk3,$ids3]=wire();
$d=$K3->decide(new SupplierSelectionPolicy($clk3,$ids3), ['tenantId'=>'t_dishnet','entityId'=>'e_ss','candidates'=>[
    ['name'=>'Aura Baby','landed'=>Money::ofDecimal('19.90','USD'),'vetted'=>true],
    ['name'=>'Nile Hygiene','landed'=>Money::ofDecimal('21.50','USD'),'vetted'=>true],
], 'evidenceRefs'=>['evt_demand_1']]);
ok('picks lowest landed cost', $d->outcome==='Aura Baby');
ok('captures policy version (2)', $d->policyVersion===2);
ok('inputs are STRUCTURED (landedMinor), not display strings', isset($d->inputs[0]['landedMinor']));
ok('records evidence refs', $d->evidenceRefs===['evt_demand_1']);
ok('the WHY is in the event log (DecisionMade)', in_array('DecisionMade', array_map(fn($r)=>$r->name(),$store3->all()), true));
$appr=new PurchaseApprovalPolicy($clk3,$ids3);
ok('over threshold -> requires human', $appr->evaluate(['amount'=>Money::ofDecimal('820.00','USD'),'threshold'=>Money::ofDecimal('500.00','USD')])->requiresHuman);

/* ---------- TASK — event-sourced, replayable (C2) ---------- */
G('Task — event-sourced; full history reconstructable (C2)');
[$K4,$store4]=wire();
$t=Task::open('t','e','pick','Pick ORD-77',null,new FrozenClock(),$idsT=new SequenceIdGenerator());
foreach($t->pullPending() as $e){} // opened
$t->assignTo('pt_ai'); $t->accept(); $t->start(); $t->complete();
$events=[]; // collect this task's raised events (Opened captured above was pulled; re-open to capture cleanly)
$t2=Task::open('t','e','pick','Pick ORD-77',null,new FrozenClock(),new SequenceIdGenerator());
$t2->assignTo('pt_ai'); $t2->accept(); $t2->start(); $t2->complete();
$hist=$t2->pullPending();
ok('5 events raised (Opened,Assigned,Accepted,Started,Completed)', count($hist)===5);
// feed them through a store to get RecordedEvents, then reconstitute
$es2=new EventStore(); foreach($hist as $e) $es2->append($e);
$rebuilt=Task::fromHistory($es2->forAggregate($t2->id));
ok('rebuilt task status = completed', $rebuilt->status()==='completed');
ok('rebuilt task owner = pt_ai', $rebuilt->owner()==='pt_ai');
threw('illegal transition still blocked', function(){ $x=Task::open('t','e','x','y',null,new FrozenClock(),new SequenceIdGenerator()); $x->complete(); }, 'Illegal');

/* ---------- PARTICIPANTS (preserved) ---------- */
G('Participant — Party vs Digital, permission scoping (preserved)');
$p=new Party('t','e','Rupesh'); $p->grantRole('finance_manager',['approve_payment','post_ledger']);
$ai=new DigitalParticipant('t','e','AI Purchasing','ai',['remind_supplier','draft_po']);
ok('party has role permission', $p->can('approve_payment'));
ok('AI can draft PO', $ai->can('draft_po'));
ok('AI cannot post ledger (scoped)', !$ai->can('post_ledger'));

echo "\n\033[1m".($fail===0?"\033[32m":"\033[31m")."$pass passed, $fail failed\033[0m\n";
exit($fail===0?0:1);
