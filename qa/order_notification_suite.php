<?php
// Verifies Order Notification Recipients against a REAL SQL engine (SQLite) with
// the exact unique index, replicating the job's claim->send->mark/release logic
// with a fake gateway. Real output, not a summary.

require dirname(__DIR__) . '/app/Support/OrderNotificationMessage.php';
use App\Support\OrderNotificationMessage;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE order_notification_recipients (id INTEGER PRIMARY KEY, tenant_id INT, name TEXT, phone TEXT, active INT DEFAULT 1)");
$db->exec("CREATE TABLE order_notification_sends (id INTEGER PRIMARY KEY, tenant_id INT, order_id INT, recipient_id INT, event_type TEXT, sent_at TEXT, message_id TEXT, created_at TEXT, UNIQUE(order_id, recipient_id, event_type))");
$db->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY, settings TEXT)");

$pass=0;$fail=0; function ck($l,$ok){global $pass,$fail; if($ok){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}}

$addR = function($t,$name,$phone,$active=1) use($db){ $db->prepare("INSERT INTO order_notification_recipients (tenant_id,name,phone,active) VALUES (?,?,?,?)")->execute([$t,$name,preg_replace('/\D+/','',$phone),$active]); return $db->lastInsertId(); };

// replicate the job: notify(tenant, order_id, sender) -> #sent. sender($phone)=>['ok'=>bool,'id'=>?]
$EVENT='order_placed';
$notify = function($tenant,$orderId,callable $sender) use($db,$EVENT){
  $sent=0;
  $rs = $db->prepare("SELECT * FROM order_notification_recipients WHERE tenant_id=? AND active=1");
  $rs->execute([$tenant]);
  foreach($rs->fetchAll(PDO::FETCH_ASSOC) as $r){
    // claim
    $ins=$db->prepare("INSERT OR IGNORE INTO order_notification_sends (tenant_id,order_id,recipient_id,event_type,created_at) VALUES (?,?,?,?,datetime('now'))");
    $ins->execute([$tenant,$orderId,$r['id'],$EVENT]);
    if($ins->rowCount()===0) continue;           // already claimed -> skip (idempotent)
    $res=$sender($r['phone']);
    if($res['ok']){
      $db->prepare("UPDATE order_notification_sends SET sent_at=datetime('now'), message_id=? WHERE order_id=? AND recipient_id=? AND event_type=?")
         ->execute([$res['id']??null,$orderId,$r['id'],$EVENT]);
      $sent++;
    } else {
      $db->prepare("DELETE FROM order_notification_sends WHERE order_id=? AND recipient_id=? AND event_type=? AND sent_at IS NULL")
         ->execute([$orderId,$r['id'],$EVENT]);  // release -> retryable
    }
  }
  return $sent;
};
$ok=fn($phone)=>['ok'=>true,'id'=>'wamid.'.$phone];
$ledgerCount=fn($orderId)=>(int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE order_id=$orderId")->fetch(PDO::FETCH_ASSOC)['c'];

echo "\n[1+2] multiple active recipients notified; inactive skipped\n";
$R1=$addR(1,'Owner','+256700111111');
$R2=$addR(1,'Manager','256700222222');
$R3=$addR(1,'OldStaff','256700999999',0);   // inactive
$sent1=$notify(1,1001,$ok);
ck('2 active recipients notified', $sent1===2);
ck('inactive recipient NOT notified (no ledger row)', (int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE recipient_id=$R3")->fetch()['c']===0);
ck('ledger has exactly 2 rows for the order', $ledgerCount(1001)===2);

echo "\n[3] duplicate job retry sends nothing extra\n";
$sentRetry=$notify(1,1001,$ok);
ck('retry sends 0 new', $sentRetry===0);
ck('ledger still 2 rows after retry', $ledgerCount(1001)===2);
$dups=$db->query("SELECT order_id,recipient_id,event_type,count(*) n FROM order_notification_sends GROUP BY 1,2,3 HAVING count(*)>1")->fetchAll();
ck('no duplicate ledger rows', count($dups)===0);

echo "\n[6] ledger records sent_at + message_id for every send\n";
$rows=$db->query("SELECT * FROM order_notification_sends WHERE order_id=1001")->fetchAll(PDO::FETCH_ASSOC);
ck('every row has sent_at', !array_filter($rows,fn($r)=>empty($r['sent_at'])));
ck('every row has message_id', !array_filter($rows,fn($r)=>empty($r['message_id'])));

echo "\n[4] tenant isolation\n";
$R4=$addR(2,'OtherShopOwner','256700333333');   // tenant 2
$notify(2,2001,$ok);                              // tenant 2's own order
ck('tenant-2 recipient never got tenant-1 order', (int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE recipient_id=$R4 AND order_id=1001")->fetch()['c']===0);
ck('notifying tenant 1 never touches tenant-2 recipients', (int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE tenant_id=2 AND order_id=1001")->fetch()['c']===0);

echo "\n[5b] failed send releases claim -> retry succeeds, no duplicate\n";
$R5=$addR(1,'Kitchen','256700444444');
$fail1=fn($phone)=>['ok'=>false];                 // first attempt fails
$sentF=$notify(1,1002,$fail1);
ck('failed send -> 0 sent', $sentF===0);
ck('failed send left NO ledger row (claim released)', (int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE order_id=1002")->fetch()['c']>=0 && (int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE order_id=1002 AND recipient_id=$R5")->fetch()['c']===0);
$sentR2=$notify(1,1002,$ok);                       // retry succeeds
ck('retry after failure sends to the recipient', $sentR2>=1);
$kitchenRows=(int)$db->query("SELECT count(*) c FROM order_notification_sends WHERE order_id=1002 AND recipient_id=$R5")->fetch()['c'];
ck('exactly ONE ledger row after fail+retry (no duplicate)', $kitchenRows===1);

echo "\n[5a] owner_alert_phone backfill -> recipient rows\n";
$db->prepare("INSERT INTO tenants (id,settings) VALUES (3,?)")->execute([json_encode(['owner_alert_phone'=>'256700111111, 256700222222 256700111111'])]); // note duplicate
// replicate backfill
foreach($db->query("SELECT * FROM tenants")->fetchAll(PDO::FETCH_ASSOC) as $t){
  $s=json_decode($t['settings']??'{}',true)?:[]; $raw=(string)($s['owner_alert_phone']??'');
  foreach(preg_split('/[,\s]+/',$raw,-1,PREG_SPLIT_NO_EMPTY)?:[] as $n){
    $p=preg_replace('/\D+/','',$n); if($p==='')continue;
    $ex=(int)$db->query("SELECT count(*) c FROM order_notification_recipients WHERE tenant_id={$t['id']} AND phone='$p'")->fetch()['c'];
    if($ex)continue;
    $db->prepare("INSERT INTO order_notification_recipients (tenant_id,name,phone,active) VALUES (?,?,?,1)")->execute([$t['id'],'Owner',$p]);
  }
}
$t3=(int)$db->query("SELECT count(*) c FROM order_notification_recipients WHERE tenant_id=3")->fetch()['c'];
ck('tenant 3 backfilled 2 distinct numbers (dup collapsed)', $t3===2);
ck('backfilled rows named Owner & active', (int)$db->query("SELECT count(*) c FROM order_notification_recipients WHERE tenant_id=3 AND name='Owner' AND active=1")->fetch()['c']===2);

echo "\n[format] message matches the approved layout\n";
$msg = OrderNotificationMessage::build([
  'order_no'=>'FS-1234','customer_name'=>'John Doe','customer_phone'=>'256700123456',
  'items_json'=>[['qty'=>2,'name'=>'Sugar 1KG'],['qty'=>1,'name'=>'Rice 5KG'],['qty'=>3,'name'=>'Bread']],
  'total'=>45000,'location'=>'Kisaasi',
  'created_at'=>strtotime('2026-06-14 07:15:00 UTC'),
],'UGX','Africa/Kampala');
echo "----\n$msg\n----\n";
ck('has New Order header', str_contains($msg,"\u{1F6D2} New Order"));
ck('Order #', str_contains($msg,'Order #: FS-1234'));
ck('Customer', str_contains($msg,'Customer: John Doe'));
ck('Phone with +', str_contains($msg,'Phone: +256700123456'));
ck('Items with x sign', str_contains($msg,"2 \u{00D7} Sugar 1KG") && str_contains($msg,"3 \u{00D7} Bread"));
ck('Total formatted', str_contains($msg,'Total: UGX 45,000'));
ck('Delivery', str_contains($msg,'Delivery: Kisaasi'));
ck('Order Time in EAT (10:15 AM)', str_contains($msg,'Order Time: 14 Jun 2026 10:15 AM'));

echo "\nRESULT: PASS $pass  FAIL $fail\n";
exit($fail?1:0);
